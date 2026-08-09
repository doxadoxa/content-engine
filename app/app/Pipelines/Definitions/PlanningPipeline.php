<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\Planning\GatherIdeas;
use App\Pipelines\Steps\Planning\ScheduleCalendar;
use App\Pipelines\Steps\Planning\SelectTopics;
use App\Pipelines\Steps\Planning\TypeAndFlagUnits;

/**
 * Planning, monthly (§4.2).
 *
 *                    ┌─ select_topics ──┐
 *   gather_ideas ────┤                  ├─ schedule_calendar
 *                    └─ type_and_flag ──┘
 *
 * The two middle steps genuinely are independent: choosing *which* ideas make
 * the month is a question about clusters and the existing corpus, while typing
 * a unit and deciding whether it needs original business data is a question
 * about the idea itself. Both read the pool, neither reads the other, and the
 * calendar needs both.
 *
 * Output is a `ContentPlan` in `draft` with its units still in `idea` —
 * approval is phase 7, and until then `plan:approve` (exit criterion 2).
 */
class PlanningPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'planning';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Planning (idea pool → monthly calendar)';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [
            GatherIdeas::class,
            SelectTopics::class,
            TypeAndFlagUnits::class,
            ScheduleCalendar::class,
        ];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return [
            // First of the month being planned. Defaults to next month.
            'month' => ['sometimes', 'date'],
        ];
    }
}
