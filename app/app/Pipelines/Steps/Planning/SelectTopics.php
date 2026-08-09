<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Research\StoreIdeas;
use App\Support\Corpus\TopicLibrary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Choose what the month is actually about (§4.2, exit criterion 3).
 *
 * Two filters, and both are about not writing the same article twice. The first
 * drops anything the project has already published on that topic — that is the
 * criterion, and it is checked against the corpus rather than against this
 * plan, because the duplicate that matters is the one already on the site. The
 * second allows one unit per cluster per month, so a month is a spread of
 * topics rather than six phrasings of the best one.
 *
 * The month's size is the project's `weekly_target` (§4.3) — a configurable
 * frequency, because §1 makes "reasonable frequency" the mitigation for
 * scaled-content abuse and a hard-coded number cannot be tuned per project.
 */
class SelectTopics extends AbstractStep
{
    public function __construct(private readonly TopicLibrary $topics) {}

    public static function key(): string
    {
        return 'select_topics';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [GatherIdeas::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $pool = $context->output(GatherIdeas::key(), IdeaPoolPayload::class);
        $capacity = $this->capacity($context, $this->month($context));

        $published = $this->publishedTopics();

        // How many units one cluster may take. It used to be exactly one,
        // which quietly overrode the operator's cadence: the keyword source
        // files a whole long tail under a handful of parent topics, so a
        // project asking for seven a week got one a week and no explanation.
        // Scaled to capacity instead, so a month can actually be filled while
        // no single cluster swallows it.
        $perCluster = $this->perCluster($pool->ideaIds, $capacity);

        $selected = [];
        $rejected = [];
        $clustersUsed = [];

        // Vectors of what is already spoken for. Seeded with the work this
        // project has already done rather than starting empty: an article that
        // is written and waiting for approval is as much a duplicate as one
        // planned five minutes ago, and `publishedTopics()` above only catches
        // exact strings on units that are already live.
        $takenVectors = $this->vectorsOfExistingWork();

        foreach ($pool->ideaIds as $id) {
            $idea = ContentItem::query()->find($id);

            if ($idea === null) {
                continue;
            }

            $topic = StoreIdeas::normalise((string) $idea->target_query);

            if ($topic !== '' && isset($published[$topic])) {
                $rejected[$id] = 'already published';

                continue;
            }

            // What the site had before this engine existed. Compared by meaning
            // rather than by wording: a project publishing in Portuguese and
            // planning in English shares no word between the two, and a string
            // check finds nothing while the same article gets written twice.
            $covered = $this->coveredElsewhere($context, $idea);

            if ($covered !== null) {
                $rejected[$id] = $covered;

                continue;
            }

            $cluster = $idea->cluster ?? $idea->getKey();

            $vector = $this->topics->rememberVector($idea, $topic !== '' ? $topic : $idea->title);

            $duplicateOfPlanned = $this->duplicateOfPlanned($context, $idea, $vector, $takenVectors);

            if ($duplicateOfPlanned !== null) {
                $rejected[$id] = $duplicateOfPlanned;

                continue;
            }

            if (($clustersUsed[$cluster] ?? 0) >= $perCluster) {
                $rejected[$id] = 'this cluster already has enough for the month';

                continue;
            }

            if (count($selected) >= $capacity) {
                $rejected[$id] = 'over the month\'s capacity';

                continue;
            }

            $clustersUsed[$cluster] = ($clustersUsed[$cluster] ?? 0) + 1;
            $selected[] = $id;
            $takenVectors[] = ['query' => $topic !== '' ? $topic : $idea->title, 'vector' => $vector];
        }

        return StepResult::success(new SelectionPayload($selected, $rejected));
    }

    /**
     * Whether the site itself already covers this, recently enough to matter.
     *
     * Recency is the point. A post from last month makes a new one a duplicate;
     * one from three years ago makes the subject fair game again, and treating
     * "ever covered" as permanent would shrink a project's plannable universe
     * every month until nothing was left.
     */
    private function coveredElsewhere(StepContext $context, ContentItem $idea): ?string
    {
        $query = (string) $idea->target_query;

        if (trim($query) === '') {
            return null;
        }

        $covered = $this->topics->alreadyCovered(
            $context->project,
            $query,
            // Reused across runs: the vector is stored on the idea, so
            // re-planning a month does not pay to embed the same words again.
            $this->topics->rememberVector($idea, $query),
        );

        if ($covered === null) {
            return null;
        }

        // In the band where distance alone cannot tell a duplicate from a
        // neighbour, ask. On this site "carpet cleaning" against its own carpet
        // article is 0.27 and "house cleaning" against "post-renovation
        // cleaning" is 0.31 — a hundredth apart, and one is a duplicate while
        // the other is a different service.
        if ($covered['distance'] > $this->topics->certainDistance()
            && ! $this->isTheSameSubject($context, $query, $covered['title'])) {
            return null;
        }

        $when = $covered['published_at'] === null ? null : Carbon::parse($covered['published_at']);

        if ($when === null) {
            // No date in the sitemap, so there is no way to age this out. It is
            // either covered forever or never, and the operator's own ask —
            // do not write the same thing twice — settles it. Said out loud in
            // the reason, because "blocked by a page of unknown age" is a
            // different fact from "blocked by last month's post".
            return config('research.block_undated_pages', true)
                ? 'the site already covers this (no date on the page): '.$covered['title']
                : null;
        }

        $months = (int) config('research.covered_for_months', 12);

        if ($when->lessThan(Carbon::now()->subMonths($months))) {
            // Old enough that the subject is fair game again. That is a refresh
            // candidate rather than a reason never to write about it.
            return null;
        }

        return sprintf(
            'the site covered this %s: %s',
            $when->diffForHumans(),
            $covered['title'],
        );
    }

    /**
     * What this project has already written, as vectors.
     *
     * Anything past being an idea counts: a draft nobody has approved yet is
     * still an article about that subject, and planning its near-twin means
     * two of our own pages competing before either has been published.
     *
     * Articles only — `roots()` guarantees that since §3 made a social unit
     * parentless, and the guarantee is load-bearing here more than anywhere
     * else in the engine. A post is not coverage of a subject: §1.3 says the
     * reverse flow matters more than the forward one, so a Threads post asking
     * a question is precisely the reason to plan the article that answers it.
     * Counting the post as work already done would invert the most important
     * claim in the spec, silently, one topic at a time.
     *
     * Read off the stored embeddings, so this costs a query rather than a bill.
     *
     * @return list<array{query: string, vector: list<float>}>
     */
    private function vectorsOfExistingWork(): array
    {
        $written = ContentItem::query()
            ->roots()
            ->where('state', '<>', ContentItemState::Idea->value)
            ->get();

        $vectors = [];

        foreach ($written as $unit) {
            $query = (string) ($unit->target_query ?? $unit->title);

            if (trim($query) === '') {
                continue;
            }

            // Computed on first use and kept. Work written before this check
            // existed has no topic vector, and skipping those would let the
            // whole back catalogue through — which is the hole this closes.
            $vectors[] = [
                'query' => $query,
                'vector' => $this->topics->rememberVector($unit, $query),
            ];
        }

        return $vectors;
    }

    /**
     * Whether this idea is the same subject as something already in the month.
     *
     * Compared against vectors already in hand, so the check costs nothing
     * beyond the arithmetic — the ideas were embedded for the site comparison
     * and the site comparison stored them.
     *
     * @param  list<float>  $vector
     * @param  list<array{query: string, vector: list<float>}>  $taken
     */
    private function duplicateOfPlanned(
        StepContext $context,
        ContentItem $idea,
        array $vector,
        array $taken,
    ): ?string {
        $nearest = null;
        $nearestDistance = 1.0;

        foreach ($taken as $already) {
            $distance = $this->distance($vector, $already['vector']);

            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest = $already['query'];
            }
        }

        // The plan's own thresholds, not the site's. Two ideas from one keyword
        // source in one language sit far closer together than an idea and a
        // published article do, and using the site's numbers here called deep
        // cleaning a duplicate of general cleaning.
        $possible = (float) config('research.planned_topic_possible', 0.25);
        $certain = (float) config('research.planned_topic_certain', 0.06);

        if ($nearest === null || $nearestDistance > $possible) {
            return null;
        }

        $query = (string) ($idea->target_query ?? $idea->title);

        // Only the nearest is judged. Asking about every pair in a month would
        // be a call per pair, and the nearest is the one that decides it.
        if ($nearestDistance > $certain
            && ! $this->isTheSameSubject($context, $query, $nearest)) {
            return null;
        }

        return "the same subject as: {$nearest}";
    }

    /**
     * Cosine distance between two unit vectors.
     *
     * The same measure pgvector's `<=>` gives, computed here because both
     * vectors are already in memory and a round trip to the database to compare
     * two arrays would be silly.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function distance(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $other = $b[$i] ?? 0.0;

            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 1.0;
        }

        return 1.0 - ($dot / (sqrt($normA) * sqrt($normB)));
    }

    /**
     * A model's answer to the one question the numbers cannot settle.
     *
     * Fails *open*: a model that will not answer must not silently empty a
     * month. Writing one duplicate is a smaller harm than planning nothing, and
     * the operator can see both articles.
     */
    private function isTheSameSubject(StepContext $context, string $planned, string $existing): bool
    {
        try {
            $answer = $context->ask(
                'utility',
                implode("\n", [
                    "Planned article: {$planned}",
                    "Article the site already has: {$existing}",
                ]),
                implode("\n", [
                    'Would these two articles cover the same subject, such that publishing both',
                    'means writing the same piece twice?',
                    'Different services, different rooms, different materials or different stages',
                    'of a job are NOT the same subject, even when they sound similar.',
                    'Answer with one word: SAME or DIFFERENT.',
                ]),
            );
        } catch (Throwable $e) {
            Log::info('Could not judge whether a topic was already covered', [
                'planned' => $planned,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        return Str::upper(trim($answer->text)) === 'SAME';
    }

    /**
     * Topics the project has already written an *article* about.
     *
     * `isLive()` rather than `Published`, so a unit currently being refreshed
     * still counts as covered — it is on the site while it is rewritten.
     *
     * Articles only, for the reason given on {@see vectorsOfExistingWork()}: a
     * published post carries a `target_query` too, and counting one here would
     * make asking a question on Threads the thing that stops the article
     * answering it from ever being planned.
     *
     * @return array<string, true>
     */
    private function publishedTopics(): array
    {
        $topics = [];

        $live = ContentItem::query()
            ->roots()
            ->whereIn('state', [ContentItemState::Published->value, ContentItemState::Refreshing->value])
            ->whereNotNull('target_query')
            ->pluck('target_query');

        foreach ($live as $query) {
            $topics[StoreIdeas::normalise((string) $query)] = true;
        }

        return $topics;
    }

    /**
     * How many units one cluster may take this month.
     *
     * Enough that the clusters available can fill the capacity asked for, and
     * no more. Twenty near-identical articles about one parent topic compete
     * with each other; one article a week when seven were asked for is a
     * setting that does nothing.
     *
     * @param  list<string>  $ideaIds
     */
    private function perCluster(array $ideaIds, int $capacity): int
    {
        $clusters = ContentItem::query()
            ->whereKey($ideaIds)
            ->select('id', 'cluster')
            ->get()
            ->map(static fn (ContentItem $idea): string => $idea->cluster ?? $idea->getKey())
            ->unique()
            ->count();

        return max(1, (int) ceil($capacity / max(1, $clusters)));
    }

    private function capacity(StepContext $context, Carbon $month): int
    {
        // From the window, not from the calendar month: half a month left means
        // half a month's articles, and the two steps have to agree or the
        // calendar ends up with more units than dates.
        return PlanningWindow::resolve($context->get('month'))
            ->capacityFor($context->project->weekly_target);
    }

    private function month(StepContext $context): Carbon
    {
        $month = $context->get('month');

        return $month === null
            ? Carbon::now()->addMonth()->startOfMonth()
            : Carbon::parse((string) $month)->startOfMonth();
    }
}
