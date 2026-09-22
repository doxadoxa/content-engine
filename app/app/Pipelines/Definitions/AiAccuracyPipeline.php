<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\AiAccuracy\CheckAccuracy;

final class AiAccuracyPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'ai_accuracy';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Assess factual claims in recorded evidence';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [CheckAccuracy::class];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return ['assessment_id' => ['required', 'ulid']];
    }
}
