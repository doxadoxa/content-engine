<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\ContentStudio\ContentStudioAction;
use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\ContentStudio\ApplyContentStudioAction;
use Illuminate\Validation\Rule;

/** Durable, operator-triggered work behind the Content Studio workspace. */
class ContentStudioPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'content_studio';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Content Studio assistant operation';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [ApplyContentStudioAction::class];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return [
            'action' => ['required', Rule::enum(ContentStudioAction::class)],
            'content_plan_id' => ['required', 'string', 'ulid'],
            'expected_version' => ['nullable', 'integer', 'min:1', 'required_if:action,refine'],
            'message' => ['nullable', 'string', 'max:5000', 'required_if:action,refine'],
            'initial' => ['nullable', 'boolean'],
            'content_idea_id' => ['nullable', 'string', 'ulid', 'required_if:action,generate_idea'],
            'content_item_id' => ['nullable', 'string', 'ulid', 'required_if:action,revise_image'],
            'instruction' => ['nullable', 'string', 'max:2000'],
            'variants' => ['nullable', 'integer', 'min:1', 'max:3'],
        ];
    }
}
