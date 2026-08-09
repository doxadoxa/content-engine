<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Visibility;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Visibility\VisibilityReport;

/**
 * Read the sweep back and record what it says.
 *
 * Thin on purpose. It computes nothing the screens do not also compute, because
 * it uses the same {@see VisibilityReport} they do — the dashboard and this run
 * log disagreeing about the score is the kind of bug nobody reports and
 * everybody quietly stops trusting the product over.
 *
 * What it adds is a number in the run's own history, so "when did visibility
 * change" is answerable from the pipeline log rather than only from the current
 * state of a table that gets overwritten.
 */
class SummariseVisibility extends AbstractStep
{
    public static function key(): string
    {
        return 'summarise_visibility';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [AskAssistants::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $report = VisibilityReport::latest();

        $context->remember('visibility.score', $report->score());
        $context->remember('visibility.mentions', $report->mentions());
        $context->remember('visibility.answered', $report->answered());
        $context->remember('visibility.by_locale', $report->byLocale());

        return StepResult::success(new VisibilitySummaryPayload(
            score: $report->score(),
            mentions: $report->mentions(),
            answered: $report->answered(),
            byLocale: $report->byLocale(),
        ));
    }
}
