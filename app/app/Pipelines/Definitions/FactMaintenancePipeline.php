<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\FactMaintenance\CheckPageFacts;

final class FactMaintenancePipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'fact_maintenance';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Check current page facts';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [CheckPageFacts::class];
    }

    /** @return array<string,mixed> */
    public function inputRules(): array
    {
        return ['check_id' => ['required', 'ulid']];
    }
}
