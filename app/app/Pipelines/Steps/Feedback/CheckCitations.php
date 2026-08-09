<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Feedback;

use App\Feedback\Contracts\CitationChecker;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * Is the brand showing up in AI answers (§9.3)?
 *
 * The metric the GEO layer of §5.3 was built to move — and until this step
 * existed, that layer was being optimised with no way to tell whether any of it
 * worked, which is exactly the position §1 accuses the cheap generators of
 * being in.
 *
 * Runs beside the degradation check rather than after it: they read the same
 * units and nothing of each other.
 */
class CheckCitations extends AbstractStep
{
    public function __construct(private readonly CitationChecker $citations) {}

    public static function key(): string
    {
        return 'check_citations';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [FetchPerformance::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $this->citations->isConfigured()) {
            return StepResult::skip('No citation checker is configured.');
        }

        if (! $context->hasOutput(FetchPerformance::key())) {
            // Nothing was published, so the fetch skipped. A skip releases its
            // dependants but hands them no payload — see StepResult::skip().
            return StepResult::skip('There is no performance data to work from.');
        }

        $metrics = $context->output(FetchPerformance::key(), MetricsPayload::class);

        $checked = 0;

        $units = ContentItem::query()
            ->whereKey($metrics->unitIds)
            ->whereNotNull('target_query')
            // Costs a model call each, so the ones with the most to lose go
            // first and the budget is a limit rather than a surprise.
            ->orderByDesc('topic_volume')
            ->limit((int) $context->get('citation_budget', 10))
            ->get();

        foreach ($units as $unit) {
            $result = $this->citations->check((string) $unit->target_query, $context->project->name);

            $unit->forceFill([
                'citations' => $result,
                'citations_checked_at' => now(),
            ])->save();

            $checked++;
        }

        $context->remember('feedback.citations_checked', $checked);

        return StepResult::success(new MetricsPayload($metrics->unitIds, $checked));
    }
}
