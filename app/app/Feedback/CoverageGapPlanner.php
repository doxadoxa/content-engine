<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Enums\SearchIntent;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectState;
use App\Pipelines\Steps\Planning\GatherIdeas;
use App\Pipelines\Steps\Research\StoreIdeas;
use App\Pipelines\Steps\SocialListen\FeedPlanner;
use App\Support\Social\EntityCoverage;
use Illuminate\Support\Str;

/**
 * §6's first consequence, and the half that was missing.
 *
 * > **Пробел покрытия становится задачей.** Тема, по которой в Threads есть
 * > разговор и отклик, а на сайте нет страницы, уходит в планировщик статей
 * > **с приоритетом**. Соцканал начинает управлять контент-планом.
 *
 * {@see EntityCoverage} has computed that list every night since 12.6 — gaps
 * named, weighed by how much conversation there was, capped and sorted — and
 * written it to `project_states.entity_coverage`, where nothing read it. A
 * measurement nobody acts on is not a consequence; it is a column. This class
 * is the sentence "уходит в планировщик статей" in code.
 *
 * **Shaped after {@see FeedPlanner}, deliberately and almost line for line.**
 * That step already turns a reason into an unplanned `ContentItem` for the
 * article planner to pick up, and every decision it had to make — dedup against
 * the corpus through {@see StoreIdeas::normalise()}, a ceiling on how many one
 * run may add, the project's own language rather than the poster's, no `state`
 * because the state machine owns it — is the same decision here. A second
 * commissioner with its own answers to those would be two definitions of "the
 * site already covers this", and the day they drift the engine starts planning
 * articles it has already written.
 *
 * **Provenance is `coverage_gap` and not `signal_id`.** See the migration for
 * the argument: a gap is a day of conversation rather than one post, it
 * frequently has no single signal behind it, and borrowing the column would
 * make `FeedPlanner` skip the question whose article this is not.
 *
 * The priority §6 asks for is applied in {@see GatherIdeas}, where the ranking
 * for the whole pool already lives.
 */
class CoverageGapPlanner
{
    /**
     * How many gaps one day may turn into ideas.
     *
     * The same kind of ceiling as `FeedPlanner::MAX_PER_RUN` and for the same
     * reason, tightened because this runs against a whole day of conversation
     * rather than an hour of it: a project that connects Threads for the first
     * time has a corpus that covers none of what its audience talks about, and
     * an uncapped first night would file twenty articles nobody asked for. §5's
     * rule is the general one — недобор допустим, перебор — нет.
     */
    private const int MAX_PER_DAY = 3;

    /**
     * What counts as «разговор» rather than one person mentioning something.
     *
     * Two, and read as the sum of both halves rather than as one of each. §6's
     * phrase is "разговор **и** отклик", and the strict reading — at least one
     * reply under one of our own posts — makes the consequence unreachable for
     * exactly the project it is most useful to: one that has just started
     * listening and has not posted yet, so there is nothing for anybody to
     * reply to. {@see EntityCoverage} keeps the two counts apart on the row, so
     * the strict reading stays available to anything that wants it; what this
     * gate is for is the subject one stranger named once.
     */
    private const int MIN_MENTIONS = 2;

    /**
     * Turn the day's coverage gaps into unplanned ideas.
     *
     * @return array{planned: list<string>, skipped: array<string, string>}
     */
    public function plan(Project $project, ProjectState $state): array
    {
        $gaps = $this->gaps($state);

        if ($gaps === []) {
            return ['planned' => [], 'skipped' => []];
        }

        // One query rather than one per gap, and the same query `FeedPlanner`
        // asks: `roots()` because a *post* about a subject is §1.3's reason to
        // write the article rather than evidence that it exists.
        $known = ContentItem::query()
            ->roots()
            ->whereNotNull('target_query')
            ->pluck('target_query')
            ->map(static fn (mixed $query): string => StoreIdeas::normalise((string) $query))
            ->flip();

        $planned = [];
        $skipped = [];

        foreach ($gaps as $gap) {
            $entity = $gap['entity'];
            $mentions = $gap['signals'] + $gap['interactions'];

            if (count($planned) >= self::MAX_PER_DAY) {
                $skipped[$entity] = 'over what one day may add to the pool';

                continue;
            }

            if ($mentions < self::MIN_MENTIONS) {
                $skipped[$entity] = 'mentioned once, which is not a conversation';

                continue;
            }

            $normalised = StoreIdeas::normalise($entity);

            if ($normalised === '' || $known->has($normalised)) {
                // The gap list already excludes anything the site has a *page*
                // for. This is the other half: an idea commissioned last night
                // is not a page yet and would otherwise be commissioned again
                // every night until somebody wrote it.
                $skipped[$entity] = 'already in the pool or on the site';

                continue;
            }

            $planned[] = $this->commission($project, $state, $gap);
            $known->put($normalised, 0);
        }

        return ['planned' => $planned, 'skipped' => $skipped];
    }

    /**
     * The gaps this snapshot named, loudest first.
     *
     * Read rather than recomputed. {@see EntityCoverage} already sorted them by
     * how much conversation there was, which is the priority §6 asks for, and
     * re-deriving the order here would be a second opinion about the same list.
     *
     * @return list<array{entity: string, signals: int, interactions: int}>
     */
    private function gaps(ProjectState $state): array
    {
        $coverage = $state->entity_coverage ?? [];
        $gaps = is_array($coverage['gaps'] ?? null) ? $coverage['gaps'] : [];

        $read = [];

        foreach ($gaps as $gap) {
            if (! is_array($gap)) {
                continue;
            }

            $entity = trim((string) ($gap['entity'] ?? ''));

            if ($entity === '') {
                continue;
            }

            $read[] = [
                'entity' => $entity,
                'signals' => (int) ($gap['signals'] ?? 0),
                'interactions' => (int) ($gap['interactions'] ?? 0),
            ];
        }

        return $read;
    }

    /**
     * One gap, as a unit in `idea`.
     *
     * No `state`, for the reason {@see FeedPlanner::plan()} gives: it is not
     * fillable, a unit starts at `idea` from the model's defaults, and the only
     * thing allowed to move it afterwards is the state machine.
     *
     * @param  array{entity: string, signals: int, interactions: int}  $gap
     */
    private function commission(Project $project, ProjectState $state, array $gap): string
    {
        $entity = $gap['entity'];
        $intent = SearchIntent::Informational;

        $idea = ContentItem::query()->create([
            'locale' => $project->default_locale,
            'type' => $intent->suggestedType(),
            'slug' => $this->slug($entity),
            // The subject as the audience says it, which is the whole value of
            // reading it off conversation rather than off a keyword tool (§4.1,
            // "живые вопросы живыми словами"). The writer names the article at
            // the end of generation.
            'title' => $entity,
            'target_query' => $entity,
            'entities' => [$entity],
            'intent' => $intent->value,
            // Its own cluster, as in `FeedPlanner`: a subject that arrived out
            // of conversation has no keyword family until the planner gives it
            // one, and guessing a parent topic files it under the wrong one.
            'cluster' => $entity,
            'planned_derivatives' => [],
            'coverage_gap' => [
                'entity' => $entity,
                'signals' => $gap['signals'],
                'interactions' => $gap['interactions'],
                'captured_on' => $state->captured_on->toDateString(),
            ],
        ]);

        return (string) $idea->getKey();
    }

    /** Unique per project, because the database says so. */
    private function slug(string $entity): string
    {
        $base = Str::limit(Str::slug($entity), 80, '') ?: 'coverage-gap';
        $slug = $base;
        $suffix = 2;

        while (ContentItem::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
