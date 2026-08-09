<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Enums\SignalKind;
use App\Enums\SignalSource;
use App\Models\Signal;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Research\DropIrrelevant;
use App\Support\Social\ProjectVocabulary;
use App\Support\Social\SignalWeight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Three intakes into one vocabulary of candidate signals (§4.1, §4.3).
 *
 * Everything the fan-out gathered arrives here as somebody else's shape and
 * leaves as a {@see CandidateSignal}: a kind, a source, a subject, a URL, the
 * project's own entities, when it happened, when it stops mattering, and what
 * it is worth. Nothing is written yet — dedup comes next, and §4.1's whole
 * complaint is about what happens when it does not.
 *
 * **Entity resolution belongs here and nowhere else.** §4.3 makes resolving
 * into the project's vocabulary a hard gate on a native post, and doing it once
 * at intake rather than per draft is the point: the resolved set is half of
 * {@see Signal::fingerprintFor()}, so a subject resolved differently by two
 * steps is a subject that escapes the thirty-day window it was supposed to be
 * caught by.
 *
 * **Where the model earns its call, and where it does not.** One call, `utility`
 * role, over the posts that came from other people — is this a question worth
 * answering, and what is it about in six words. That judgement is the reason
 * §4.1 says this contour gives the article planner "живые вопросы живыми
 * словами": a question mark is not a question ("great post, right?") and a
 * question without one is still a question ("wondering if vinegar does anything
 * for limescale"). Everything else stays deterministic — dates, URLs, entity
 * resolution, weights, and the whole of the RSS side, where a headline is
 * already a subject and asking a model to restate it would be paying for a copy.
 *
 * The call fails open. A provider outage degrades the classification to "does
 * it end in a question mark" and the run still produces signals, because §4.1
 * says this contour pays for itself on its own and a contour that stops when a
 * model is unavailable does not.
 *
 * **Our own posts are not "чужие разговоры".** When §11.2's scope is missing
 * the search returns our own posts, and those are classified as
 * {@see SignalKind::Repurpose} without a model call and never as questions:
 * §1.3 sends the planner questions from search results and from replies under
 * our posts, not questions we asked ourselves. They are still signals — a
 * degraded run produces something — they are simply worth less and they do not
 * feed the reverse flow.
 */
class Normalise extends AbstractStep
{
    /**
     * How long a reactive signal is worth acting on (§5).
     *
     * Three days. §5 puts a TTL on the reactive band because "автоматический
     * комментарий к позавчерашней новости хуже молчания", and this is that
     * sentence as a number.
     */
    private const int REACTIVE_TTL_HOURS = 72;

    /**
     * How long a question stays open, in days.
     *
     * Thirty, which is §4.1's own number — the window it deduplicates against —
     * and it is a different kind of window from the reactive three days above.
     * The reactive TTL is a *kill*: a draft that misses it is destroyed rather
     * than published late. This one is a shelf life. A question is still worth
     * an article a week after it was asked, so the window has to be long enough
     * that {@see FeedPlanner} can come back to a question it held back; it
     * cannot be infinite, because a live signal is also a *block*.
     *
     * That is the part that was wrong here. Questions were given no window at
     * all, and nothing marks a question consumed, so a question the planner
     * declined stayed in {@see Signal::scopeLive()} forever — and
     * {@see Deduplicate} matches every new arrival against exactly that set.
     * One question declined once meant its subject could never be raised again
     * by anybody, for the life of the project. Thirty days makes the block
     * expire on the same schedule as the published-work half of §4.1's dedup,
     * after which the same subject arrives as a new signal with a fresh weight,
     * and {@see Signal::scopeReapable()} can finally clear the row.
     *
     * The window is put on the row rather than on the dedup query on purpose.
     * `scopeLive()` is what the article planner and the social planner both
     * read; a per-reader window would mean each reader inventing its own idea
     * of how long a question lasts, and the reaper — which keys on
     * `expires_at` — would keep none of them.
     */
    private const int QUESTION_TTL_DAYS = 30;

    /** The kinds §5 calls reactive, and the only ones given a kill window. */
    private const array REACTIVE = [
        SignalKind::News,
        SignalKind::Trend,
        SignalKind::Mention,
    ];

    /** Titles are a `text` column, but a subject is a subject. */
    private const int MAX_TITLE = 300;

    /** How many posts one classification call covers. */
    private const int MAX_CLASSIFIED = 40;

    public function __construct(private readonly ProjectVocabulary $vocabulary) {}

    public static function key(): string
    {
        return 'normalise';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [FetchSearch::key(), FetchFeeds::key(), FetchMentions::key()];
    }

    /**
     * On the expensive queue, because of the one classification call.
     *
     * §3.2's rule rather than a judgement about how long it takes: a step that
     * reaches a provider occupies a worker for as long as the provider feels
     * like taking, and every quick step in every other run is queued behind it.
     */
    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $search = $context->hasOutput(FetchSearch::key())
            ? $context->output(FetchSearch::key(), SearchHarvestPayload::class)
            : null;

        $feeds = $context->hasOutput(FetchFeeds::key())
            ? $context->output(FetchFeeds::key(), FeedHarvestPayload::class)
            : null;

        $mentions = $context->hasOutput(FetchMentions::key())
            ? $context->output(FetchMentions::key(), MentionHarvestPayload::class)
            : null;

        if ($search === null && $feeds === null && $mentions === null) {
            // Every intake skipped, which for three of the four projects here
            // is the normal Tuesday. Unnecessary rather than impossible.
            return StepResult::skip('Nothing was gathered: this project has no Threads connection and no feeds.');
        }

        $vocabulary = $this->vocabulary->entities($context->project);
        $now = CarbonImmutable::now()->utc();

        $searched = $search === null ? [] : $search->posts;
        $replies = $mentions === null ? [] : $mentions->replies;
        $items = $feeds === null ? [] : $feeds->items;

        // Posts written by other people — the half of §1 worth a model call.
        $foreign = [
            ...array_values(array_filter(
                $searched,
                static fn (array $post): bool => ($post['own'] ?? false) !== true,
            )),
            ...array_map(
                static fn (array $reply): array => $reply + ['reply' => true],
                $replies,
            ),
        ];

        $judged = $this->classify($context, $foreign);

        $candidates = [];

        foreach ($foreign as $index => $post) {
            $candidate = $this->fromForeignPost($post, $judged[$index] ?? null, $vocabulary, $now);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        foreach ($searched as $post) {
            if (($post['own'] ?? false) !== true) {
                continue;
            }

            $candidate = $this->fromOwnPost($post, $vocabulary, $now);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        foreach ($items as $item) {
            $candidate = $this->fromFeedItem($item, $vocabulary, $now);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        $context->remember('social_listen.candidates', count($candidates));

        return StepResult::success(new CandidatePayload($candidates));
    }

    /**
     * Ask once whether each of these is a question, and what it is about.
     *
     * A line per post, `n | QUESTION or OTHER | subject`, rather than JSON. The
     * shape is the same one {@see DropIrrelevant}
     * uses and for the same reason: a model that half-answers a line protocol
     * loses that line, where a model that half-answers a JSON document loses
     * the document.
     *
     * @param  list<array<string, mixed>>  $posts
     * @return array<int, array{question: bool, subject: string}>
     */
    private function classify(StepContext $context, array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $numbered = [];

        foreach (array_slice($posts, 0, self::MAX_CLASSIFIED) as $index => $post) {
            $text = Str::squish((string) ($post['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $numbered[$index + 1] = Str::limit($text, 400, '');
        }

        if ($numbered === []) {
            return [];
        }

        try {
            $answer = $context->ask(
                'utility',
                implode("\n", array_map(
                    static fn (int $n, string $text): string => "{$n}. {$text}",
                    array_keys($numbered),
                    array_values($numbered),
                )),
                implode("\n", [
                    'Each numbered line is a social media post.',
                    'For each one, answer with a single line in this exact format:',
                    '',
                    '  <number> | QUESTION | <subject>',
                    '  <number> | OTHER | <subject>',
                    '',
                    'QUESTION means somebody is asking something a business in this field',
                    'could usefully answer. Rhetorical questions, complaints and jokes are OTHER.',
                    'A post can be a question without a question mark, and a question mark',
                    'does not make a post a question.',
                    '',
                    'The subject is what the post is about, in at most eight words,',
                    'in the language the post is written in. Not a summary of the post —',
                    'the thing being discussed, phrased so it could be a headline.',
                    '',
                    'One line per numbered post. Nothing else.',
                ]),
            );
        } catch (Throwable $e) {
            // Fails open — see the class docblock. §4.1's contour pays for
            // itself on its own, and one that stops when a provider is down
            // does not.
            Log::warning('Listening could not classify this hour’s posts; falling back to the question mark', [
                'project' => $context->project->slug,
                'reason' => $e->getMessage(),
            ]);

            return [];
        }

        return $this->parse($answer->text, array_keys($numbered));
    }

    /**
     * @param  list<int>  $expected  the line numbers that were actually asked about
     * @return array<int, array{question: bool, subject: string}>
     */
    private function parse(string $text, array $expected): array
    {
        $judged = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $parts = array_map(trim(...), explode('|', $line, 3));

            if (count($parts) < 2) {
                continue;
            }

            $number = (int) preg_replace('/\D+/', '', $parts[0]);

            if (! in_array($number, $expected, true)) {
                continue;
            }

            $judged[$number - 1] = [
                'question' => str_contains(mb_strtoupper($parts[1]), 'QUESTION'),
                'subject' => Str::limit(Str::squish($parts[2] ?? ''), self::MAX_TITLE, ''),
            ];
        }

        return $judged;
    }

    /**
     * A post somebody else wrote, or a reply under one of ours.
     *
     * @param  array<string, mixed>  $post
     * @param  array{question: bool, subject: string}|null  $judged
     * @param  list<string>  $vocabulary
     */
    private function fromForeignPost(array $post, ?array $judged, array $vocabulary, CarbonImmutable $now): ?CandidateSignal
    {
        $text = Str::squish((string) ($post['text'] ?? ''));

        if ($text === '') {
            return null;
        }

        $isReply = ($post['reply'] ?? false) === true;

        // The fallback when the model did not answer, or was not asked: a
        // question mark. Crude, and honest about being crude — it is what the
        // class docblock promises a provider outage degrades to.
        $question = $judged['question'] ?? str_contains($text, '?');

        $subject = $judged['subject'] ?? '';
        $title = $subject !== '' ? $subject : Str::limit($text, self::MAX_TITLE, '');

        if ($isReply && ! $question) {
            // A reply that is not a question is a conversation, not a reason to
            // write. It is already an `Interaction` owed an answer (§3), and
            // making a signal of it too would put the duty queue into the
            // planner's ranking twice over.
            return null;
        }

        // {@see SignalKind::Reply} is deliberately not produced. It describes
        // the duty queue's raw material, and the duty queue is `interactions` —
        // a reply worth a *signal* is one worth writing about, which is the
        // question above.
        return $this->candidate(
            kind: $question ? SignalKind::Question : SignalKind::Trend,
            source: $isReply ? SignalSource::ThreadsWebhook : SignalSource::ThreadsKeywordSearch,
            externalId: $this->text($post, 'id'),
            title: $title,
            url: $this->text($post, 'permalink'),
            text: $text,
            occurredAt: $this->instant($post[$isReply ? 'timestamp' : 'posted_at'] ?? null),
            vocabulary: $vocabulary,
            now: $now,
            degraded: false,
            raw: $post,
        );
    }

    /**
     * One of ours, returned because §11.2's scope is missing.
     *
     * No model call: the classification exists to find other people's
     * questions, and our own post is not one whatever it ends with.
     *
     * @param  array<string, mixed>  $post
     * @param  list<string>  $vocabulary
     */
    private function fromOwnPost(array $post, array $vocabulary, CarbonImmutable $now): ?CandidateSignal
    {
        $text = Str::squish((string) ($post['text'] ?? ''));

        if ($text === '') {
            return null;
        }

        return $this->candidate(
            kind: SignalKind::Repurpose,
            source: SignalSource::ThreadsKeywordSearch,
            externalId: $this->text($post, 'id'),
            title: Str::limit($text, self::MAX_TITLE, ''),
            url: $this->text($post, 'permalink'),
            text: $text,
            occurredAt: $this->instant($post['posted_at'] ?? null),
            vocabulary: $vocabulary,
            now: $now,
            degraded: true,
            raw: $post,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $vocabulary
     */
    private function fromFeedItem(array $item, array $vocabulary, CarbonImmutable $now): ?CandidateSignal
    {
        $title = Str::squish((string) ($item['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        return $this->candidate(
            kind: SignalKind::News,
            source: SignalSource::Rss,
            externalId: $this->text($item, 'id'),
            title: Str::limit($title, self::MAX_TITLE, ''),
            url: $this->text($item, 'url'),
            text: $title.' '.(string) ($item['summary'] ?? ''),
            occurredAt: $this->instant($item['published_at'] ?? null),
            vocabulary: $vocabulary,
            now: $now,
            degraded: false,
            raw: $item,
        );
    }

    /**
     * The common tail: resolve, date, weigh, and refuse the already-dead.
     *
     * @param  list<string>  $vocabulary
     * @param  array<string, mixed>  $raw
     */
    private function candidate(
        SignalKind $kind,
        SignalSource $source,
        ?string $externalId,
        string $title,
        ?string $url,
        string $text,
        ?CarbonImmutable $occurredAt,
        array $vocabulary,
        CarbonImmutable $now,
        bool $degraded,
        array $raw,
    ): ?CandidateSignal {
        // Resolved against the title *and* the body: the subject the model
        // extracted is six words long and the entity that makes it this
        // project's business is often only in the post.
        $entities = ProjectVocabulary::resolve($title.' '.$text, $vocabulary);

        // Not nullable on the column, so an undated item is stamped with now.
        // The *weight* is given the real thing, which may be null, so an
        // undated feed scores zero freshness rather than full marks.
        $stamp = $occurredAt ?? $now;

        $expiresAt = match (true) {
            in_array($kind, self::REACTIVE, true) => $stamp->addHours(self::REACTIVE_TTL_HOURS),
            $kind === SignalKind::Question => $stamp->addDays(self::QUESTION_TTL_DAYS),
            default => null,
        };

        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo($now)) {
            // Born expired: a three-day-old news item read from a feed that
            // only publishes weekly, or — at the other end of the scale — a
            // question dug up from a month-old thread. §5 kills a reactive
            // draft that misses its window, and writing the row first so the
            // reaper can delete it an hour later is the same outcome with a row
            // in between.
            return null;
        }

        try {
            return CandidateSignal::make(
                kind: $kind,
                source: $source,
                externalId: $externalId,
                title: $title,
                url: $url,
                entities: $entities,
                occurredAt: $stamp,
                expiresAt: $expiresAt,
                weight: SignalWeight::for($kind, $entities, $occurredAt, $degraded, $now),
                raw: $raw,
            );
        } catch (InvalidArgumentException) {
            // `fingerprintFor()` refuses a subject that is neither a title nor
            // an entity. It cannot happen from here — the title is checked
            // above — but the guard is the model's and this is the call site
            // its docblock names, so it is caught rather than assumed away.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (is_int($value)) {
            return CarbonImmutable::createFromTimestamp($value)->utc();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
