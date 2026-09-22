<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\AiSampling\SampleAnswer;

final class AiSamplePipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'ai_sample';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Sample one AI answer';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [SampleAnswer::class];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return ['cell_id' => ['required', 'ulid']];
    }
}
