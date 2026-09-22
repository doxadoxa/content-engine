<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\Proposals\WritePageProposal;

final class PageImprovementPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'page_improvement';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Review an existing page improvement';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [WritePageProposal::class];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return ['proposal_id' => ['required', 'ulid'], 'generation_id' => ['required', 'uuid']];
    }
}
