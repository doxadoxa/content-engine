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
            ->inState(ContentItemState::Idea)
            ->whereNull('content_plan_id')
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

        $sorted = $ideas->sortByDesc(
            function (ContentItem $item) use ($performance): float {
                $opportunity = ($item->topic_volume ?? 0) / (($item->topic_difficulty ?? 50) + 10);

                return $opportunity * ($performance[$item->cluster ?? ''] ?? 1.0);
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
        $rows = ContentItem::query()
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
