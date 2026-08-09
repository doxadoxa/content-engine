<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Enums\PipelineRunStatus;
use App\Integrations\Exceptions\ThreadsCredentialRejected;
use App\Integrations\Exceptions\ThreadsRefused;
use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Integrations\Threads\ThreadsConnection;
use App\Integrations\Threads\ThreadsPost;
use App\Integrations\Threads\ThreadsSearch;
use App\Integrations\Threads\ThreadsSearchAnswer;
use App\Integrations\Threads\ThreadsSearchMode;
use App\Integrations\Threads\ThreadsSearchType;
use App\Models\PipelineRun;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Support\Social\ProjectVocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * `keyword_search` over the project's own entities (§4.1).
 *
 * §4.1 asks for two modes — "в режимах `RECENT` и `TAG`" — and the endpoint
 * splits that across two parameters: `search_mode` is KEYWORD or TAG and
 * `search_type` is TOP or RECENT. So each term goes out as a keyword search
 * sorted by recency, and again as a hashtag search when it is a term a hashtag
 * could be (see {@see isTag()}); both are RECENT because §4.1 is about what is
 * being said now. A TOP ranking is a popularity ordering of things that may be
 * weeks old, which is the seasonal band's question and not this one's.
 *
 * **The three answers of §11.2, and none of them a failure.** This is the
 * caller {@see ThreadsSearch}'s docblock is addressed to, so it is worth being
 * explicit about what each one does here:
 *
 * - *Nothing* — a sensitive word, or an honestly quiet one. The term is dropped
 *   and the next one is asked. Nothing is logged at warning level and nothing
 *   is retried: §2 says an empty answer does not even count against the budget,
 *   and a retry ladder over a word the platform has decided to be quiet about
 *   walks itself to the daily ceiling.
 * - *OwnPostsOnly* — the scope of §11.2 was never approved. The posts are ours,
 *   they are kept, and `degraded` travels with them so that
 *   {@see Normalise} can weigh and classify them as ours rather than as the
 *   "чужие разговоры" §1 puts half the channel's value in. A degraded run
 *   produces signals. It does not produce a failure and it does not produce
 *   silence.
 * - *BudgetSpent* — the 2 200/24 h window of §2 is full. The loop stops where
 *   it is and the step succeeds with what it already has, because §5 is
 *   unambiguous that a ceiling is a ceiling and undershooting is allowed.
 *
 * Only a transport fault fails the step, and it fails it retryably: the hour is
 * lost, the next one is not. A {@see ThreadsRefused} that is not about
 * permissions is narrower than that — it is one term the endpoint will not take
 * — and it must not silence the other seven, so it is logged and skipped.
 *
 * ⚠️ Unverified against a live account, like every Threads path here. The
 * behaviour of `search_mode=TAG` against a multi-word term used to be a
 * documented guess; it is now simply not asked — {@see isTag()} keeps phrases
 * out of the tag pass, so the terms that go out carry their `#` stripped and no
 * space for the platform to have an opinion about.
 */
class FetchSearch extends AbstractStep
{
    /**
     * Posts per term per mode.
     *
     * Small deliberately. Every post that comes back is a candidate the model
     * in {@see Normalise} has to read, so the page size is a cost and not a
     * thoroughness setting — and §5 caps what any of this can turn into at one
     * reactive post a week.
     */
    private const int PER_TERM = 20;

    /**
     * How far back to look when there is no previous run to bound it.
     *
     * A day rather than an hour: the first run of a newly connected project
     * should see yesterday's conversation, and the platform takes `since` as a
     * date anyway.
     */
    private const int COLD_START_HOURS = 24;

    public function __construct(
        private readonly ThreadsSearch $search,
        private readonly ThreadsConnection $connection,
        private readonly ProjectVocabulary $vocabulary,
    ) {}

    public static function key(): string
    {
        return 'fetch_search';
    }

    public function handle(StepContext $context): StepResult
    {
        if ($this->connection->for($context->project) === null) {
            // The normal case for three of the four projects this engine runs.
            // Unnecessary, not impossible — so a skip, and the run goes on to
            // read the feeds.
            return StepResult::skip('This project has no Threads connection.');
        }

        $terms = $this->vocabulary->terms($context->project);

        if ($terms === []) {
            return StepResult::skip(
                'This project has no entities to listen for yet. Research and generation fill the vocabulary.'
            );
        }

        $since = $this->since($context);

        $posts = [];
        $degraded = false;
        $budgetSpent = false;

        $asked = 0;

        foreach ($terms as $term) {
            foreach ([ThreadsSearchMode::Keyword, ThreadsSearchMode::Tag] as $mode) {
                if ($mode === ThreadsSearchMode::Tag && ! self::isTag($term)) {
                    continue;
                }

                $answer = $this->ask($context, $term, $mode, $since);

                // What actually left the building. A refusal is a request the
                // platform answered; a full window and a missing credential are
                // the two outcomes where nothing was sent, which is the
                // distinction {@see ThreadsSearchAnswer::reachedThePlatform()}
                // exists to draw for the budget and for §8's metering.
                if ($answer === null || $answer->reachedThePlatform()) {
                    $asked++;
                }

                if ($answer === null) {
                    continue;
                }

                if ($answer->isBudgetSpent()) {
                    // §2's window is full for this project. Everything after
                    // this would be refused for the same reason, so the loop
                    // stops rather than asking fifteen more times.
                    $budgetSpent = true;

                    break 2;
                }

                $degraded = $degraded || $answer->isDegraded();

                foreach ($answer->posts as $post) {
                    $posts[] = $this->row($post, $term, $mode, $answer->isDegraded());
                }
            }
        }

        // §7's last line — "чего движок делать не стал и почему" — is the whole
        // reason these are remembered rather than only returned. The payload
        // reaches the next step and nothing else; `remember()` reaches the run,
        // which is what 12.6's daily summary reads. A degraded hour and a spent
        // window are both "what the engine did not do", and both are invisible
        // to an operator otherwise: the degradation logs one `notice` the day
        // it starts, and the full window logs nothing at all.
        $context->remember('social_listen.search_posts', count($posts));
        $context->remember('social_listen.search_requests', $asked);
        $context->remember('social_listen.degraded', $degraded);
        $context->remember('social_listen.budget_spent', $budgetSpent);

        if ($budgetSpent) {
            $context->remember(
                'social_listen.stopped_because',
                'The Threads search budget of §2 — 2 200 requests per account per 24 h — is spent, '
                ."so this hour asked {$asked} of its ".count($terms)
                .' terms and the rest wait for the window to roll.',
            );
        }

        if ($degraded) {
            $context->remember(
                'social_listen.degraded_because',
                'The '.ThreadsSearch::SCOPE.' scope of §11.2 is not approved for this project, '
                .'so listening read our own posts instead of other people’s conversations.',
            );
        }

        return StepResult::success(new SearchHarvestPayload(
            posts: $posts,
            terms: $terms,
            degraded: $degraded,
            budgetSpent: $budgetSpent,
        ));
    }

    /**
     * One term in one mode, or nothing.
     *
     * Null means "this term did not work out"; the failures that mean "this run
     * did not work out" are thrown.
     */
    private function ask(
        StepContext $context,
        string $term,
        ThreadsSearchMode $mode,
        CarbonImmutable $since,
    ): ?ThreadsSearchAnswer {
        try {
            return $this->search->search(
                project: $context->project,
                query: $mode === ThreadsSearchMode::Tag ? ltrim($term, '#') : $term,
                mode: $mode,
                type: ThreadsSearchType::Recent,
                since: $since,
                limit: self::PER_TERM,
            );
        } catch (ThreadsUnavailable $e) {
            // The platform said nothing, or said 429/5xx. Retryable, and the
            // whole step: a listening hour with half its terms asked is a
            // listening hour whose dedup window is wrong.
            throw new RetryableStepFailure("Threads did not answer the listening run: {$e->getMessage()}", previous: $e);
        } catch (ThreadsCredentialRejected $e) {
            // The connection is finished and only a human reconnecting fixes
            // it. `ThreadsConnection` has already recorded that on the
            // integration; retrying would only rediscover it every hour.
            throw new TerminalStepFailure("Threads has stopped honouring this project's connection: {$e->getMessage()}", previous: $e);
        } catch (ThreadsRefused $e) {
            // One term the endpoint will not take — a character it dislikes, a
            // tag search over a phrase. The other seven are still worth asking.
            Log::info('Threads refused one listening term', [
                'project' => $context->project->slug,
                'term' => $term,
                'mode' => $mode->value,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whether this term is something a hashtag search can be asked for.
     *
     * A tag is one token. The vocabulary of §4.1 is derived from the corpus —
     * entities and cluster names — so a good half of it is phrases: "hard
     * water", "kettle descaler". Sent as `search_mode=TAG` those are a request
     * the platform has no way to satisfy, and the two outcomes are the two
     * expensive ones: a refusal per phrase per hour, or an answer for the first
     * word alone, which is listening to something else. Either way it is half
     * the tag budget of §2 spent on a question we did not mean to ask.
     *
     * Single-token is the whole test, and it is deliberately not clever about
     * turning a phrase into a tag: `#hardwater` is a guess about what people
     * write, and a wrong guess costs the same request as the phrase did.
     *
     * ⚠️ Which of the two failures a multi-word tag search actually produces is
     * unverified, like everything else on this path — see the class docblock.
     * The filter makes the question moot rather than answering it.
     */
    private static function isTag(string $term): bool
    {
        $term = ltrim(trim($term), '#');

        return $term !== '' && preg_match('/\s/u', $term) !== 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ThreadsPost $post, string $term, ThreadsSearchMode $mode, bool $own): array
    {
        return [
            'id' => $post->id,
            'text' => $post->text,
            'username' => $post->username,
            'permalink' => $post->permalink,
            'posted_at' => $post->postedAt?->toIso8601String(),
            'media_type' => $post->mediaType,
            'term' => $term,
            'mode' => $mode->value,
            // Whether this came out of the degraded path of §11.2. Per post
            // rather than per run, because a project whose scope is approved
            // half way through an hour would otherwise have every post it read
            // that hour marked as its own.
            'own' => $own,
        ];
    }

    /**
     * Bounded to the last completed listening run.
     *
     * §4.1 runs hourly and `RECENT` returns the same conversation every time it
     * is asked, so without a bound the same posts are re-read and re-classified
     * all day. The dedup of §4.1 would collapse them anyway — this is about not
     * paying for the model call that discovers it.
     */
    private function since(StepContext $context): CarbonImmutable
    {
        $lastRun = PipelineRun::query()
            ->where('pipeline', 'social_listen')
            ->where('status', PipelineRunStatus::Completed->value)
            ->whereKeyNot($context->run->getKey())
            ->max('finished_at');

        $default = CarbonImmutable::now()->utc()->subHours(self::COLD_START_HOURS);

        if (! is_string($lastRun) && ! $lastRun instanceof \DateTimeInterface) {
            return $default;
        }

        // Never further back than the cold-start window and never in the
        // future: a run finished under an hour ago still asks for today, since
        // the platform reads `since` as a date.
        return CarbonImmutable::parse($lastRun)->utc()->max($default);
    }
}
