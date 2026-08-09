<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\Feedback\CheckCitations;
use App\Pipelines\Steps\Feedback\DetectDegradation;
use App\Pipelines\Steps\Feedback\FetchEngagement;
use App\Pipelines\Steps\Feedback\FetchPerformance;
use App\Pipelines\Steps\Feedback\QueueRefresh;

/**
 * Feedback (§4 pipeline 6, §9).
 *
 *   fetch_performance ───┬─ detect_degradation → queue_refresh
 *   fetch_engagement  ───┘
 *                        └─ check_citations
 *
 * The two fetches are independent: they read different Google accounts, each
 * connected on its own, and a project with one and not the other must still get
 * the half it has.
 *
 * §1's second differentiator, and the one that makes the engine something other
 * than a generator: it looks at what happened and changes what it does next.
 * The degradation branch feeds the refresh queue; the citation branch measures
 * the thing the GEO layer exists for.
 */
class FeedbackPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'feedback';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Feedback (performance → refresh queue and planning signals)';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [
            FetchPerformance::class,
            FetchEngagement::class,
            DetectDegradation::class,
            CheckCitations::class,
            QueueRefresh::class,
        ];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return [
            'days' => ['sometimes', 'integer', 'min:7', 'max:180'],
            'citation_budget' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }
}
