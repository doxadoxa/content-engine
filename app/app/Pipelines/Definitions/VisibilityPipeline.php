<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\Visibility\AskAssistants;
use App\Pipelines\Steps\Visibility\GeneratePrompts;
use App\Pipelines\Steps\Visibility\SummariseVisibility;

/**
 * Visibility in AI answers (§9.3, the GEO half).
 *
 *   generate_prompts → ask_assistants → summarise_visibility
 *
 * Strictly sequential, unlike feedback's two independent fetches: there is
 * nothing to ask until the prompts exist, and nothing to summarise until the
 * answers do.
 *
 * Separate from the feedback pipeline rather than bolted onto it, for two
 * reasons that both come down to grain. Feedback is per article — it reads what
 * each published unit did and queues refreshes. This is per project: whether the
 * brand shows up at all, for questions no article was written to answer. And it
 * runs on a different clock, because sixty paid model calls is a weekly
 * question, not a nightly one.
 */
class VisibilityPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'visibility';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Visibility (what the assistants say about the brand)';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [
            GeneratePrompts::class,
            AskAssistants::class,
            SummariseVisibility::class,
        ];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return [
            'prompts_per_locale' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'max_answers' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }
}
