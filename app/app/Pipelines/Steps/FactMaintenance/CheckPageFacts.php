<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\FactMaintenance;

use App\FactMaintenance\FactMaintenance;
use App\Models\FactMaintenanceCheck;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

final class CheckPageFacts extends AbstractStep
{
    public static function key(): string
    {
        return 'check_page_facts';
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function timeout(): int
    {
        return 1800;
    }

    public function handle(StepContext $context): StepResult
    {
        app(FactMaintenance::class)->perform(FactMaintenanceCheck::query()->findOrFail((string) $context->get('check_id')), $context);

        return StepResult::success();
    }
}
