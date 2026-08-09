<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;

/**
 * The unplanned ideas, best opportunity first (§4.2).
 *
 * Only units that are still `idea` and still unplanned: one already on a
 * calendar belongs to that month, and re-planning it would put the same article
 * in two months.
 */
class GatherIdeas extends AbstractStep
{
    public static function key(): string
    {
        return 'gather_ideas';
    }

    public function handle(StepContext $context): StepResult
    {
        $ideas = ContentItem::query()
            ->roots()
            ->inState(ContentItemState::Idea)
            ->whereNull('content_plan_id')
            // Eager, because the sort below reads the signal's weight for every
            // listened idea and a lazy relation would make ranking the pool one
            // query per row.
            ->with('signal')
            ->get();

        if ($ideas->isEmpty()) {
            throw new TerminalStepFailure(
                'There are no unplanned ideas for this project. Run the research pipeline first.'
            );
        }

        // Opportunity, then what actually happened (§9.1: "что сработало —
        // планировать больше, что нет — меньше").
        //
        // A multiplier rather than a replacement: a cluster that earned clicks
        // is worth more of, but a project with no history yet must still be
        // able to plan a month — and it does, because every multiplier is 1.
        $performance = $this->clusterPerformance();

        // Three tiers, not one score. An idea that came out of a real
        // conversation is ranked ahead of the keyword pool rather than against
        // it, and §6 asks for exactly that: a subject people are discussing
        // while the site has no page for it "уходит в планировщик статей **с
        // приоритетом**".
        //
        // The top tier is the coverage gap of §6, above the signal that was
        // already there. A signal is one post somebody wrote; a gap is a whole
        // day of conversation measured against the whole corpus and found to
        // have no page behind it at all, which is the strongest evidence this
        // engine produces about what to write next. It is a third tier rather
        // than a number folded into the second because the two have no shared
        // scale — a signal's weight is freshness and resolution, a gap's is how
        // many times the subject came up — and §9's method is that a number
        // nobody can reconstruct is a number nobody acts on.
        //
        // A tier rather than a bonus, because the two cannot share a scale. The
        // opportunity score is volume over difficulty, and a listened question
        // has neither — nobody measured its search volume, because the question
        // was asked in a thread rather than in a search box. Inventing a volume
        // for it would put a fabricated number into the one metric that ranks
        // everything else, and scoring it honestly as zero is what put it
        // behind every keyword idea in the pool and made it unselectable: the
        // whole reverse flow of §1.3 arrived in the plan and sorted to the
        // bottom of it, which is the same as not arriving.
        //
        // Within the tier the ordering is the signal's own weight, so a hot
        // question outranks a lukewarm one.
        $sorted = $ideas->sortByDesc(
            function (ContentItem $item) use ($performance): array {
                $opportunity = ($item->topic_volume ?? 0) / (($item->topic_difficulty ?? 50) + 10);

                $signal = $item->signal;
                $gap = $item->coverage_gap;

                return [
                    match (true) {
                        $gap !== null => 2,
                        $signal !== null => 1,
                        default => 0,
                    },
                    // Inside each tier, the measure that tier is about: how
                    // much conversation the gap was made of, the signal's own
                    // weight so a hot question outranks a lukewarm one, and the
                    // opportunity score outside both. The signal is read from
                    // the relation rather than from `signal_id`, because a
                    // signal reaped out from under an idea would leave the id
                    // set and the row gone.
                    match (true) {
                        $gap !== null => (float) ($gap['signals'] + $gap['interactions']),
                        $signal !== null => (float) $signal->weight,
                        default => $opportunity * ($performance[$item->cluster ?? ''] ?? 1.0),
                    },
                ];
            }
        )->values();

        /** @var list<string> $ids */
        $ids = $sorted->map(static fn (ContentItem $item): string => $item->getKey())->values()->all();

        return StepResult::success(new IdeaPoolPayload($ids));
    }

    /**
     * How each cluster has actually performed, as a multiplier around 1.
     *
     * Clicks per live unit, normalised against the project's own average — so
     * this is "better or worse than the rest of what we publish" rather than an
     * absolute anybody would have to calibrate. Bounded either side, because a
     * single runaway article should tilt the next month rather than decide it.
     *
     * @return array<string, float>
     */
    private function clusterPerformance(): array
    {
        // Articles only: search metrics are per page, so a social post in the
        // cluster contributes a guaranteed zero and halves the multiplier of a
        // cluster that actually earned its clicks.
        $rows = ContentItem::query()
            ->roots()
            ->whereNotNull('cluster')
            ->whereIn('state', [ContentItemState::Published->value, ContentItemState::Refreshing->value])
            ->withSum('metrics as clicks', 'clicks')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $byCluster = [];

        foreach ($rows as $row) {
            $cluster = (string) $row->cluster;
            $byCluster[$cluster][] = (float) ($row->getAttribute('clicks') ?? 0);
        }

        $averages = array_map(
            static fn (array $clicks): float => array_sum($clicks) / count($clicks),
            $byCluster,
        );

        $overall = array_sum($averages) / count($averages);

        if ($overall <= 0.0) {
            // Published, but nothing has been measured yet. No opinion.
            return [];
        }

        return array_map(
            static fn (float $average): float => max(0.5, min(2.0, $average / $overall)),
            $averages,
        );
    }
}
