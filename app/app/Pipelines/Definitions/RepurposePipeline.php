<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\Repurpose\GenerateHero;
use App\Pipelines\Steps\Repurpose\LinkInternally;
use App\Pipelines\Steps\Repurpose\LoadParent;
use App\Pipelines\Steps\Repurpose\SaveDerivatives;
use App\Pipelines\Steps\Repurpose\WritePosts;

/**
 * Repurpose, per parent (§4 pipeline 4, §8).
 *
 *                 ┌─ link_internally → write_posts ─┐
 *   load_parent ──┤                                 ├─ save_derivatives
 *                 └─ generate_hero ─────────────────┘
 *
 * The hero runs beside the linking and writing because a picture has nothing to
 * do with either, and it is the slowest thing here — a minute of image
 * generation should overlap the text, not follow it.
 *
 * §1's third differentiator lives in `save_derivatives`: a post that does not
 * share the article's entities is refused rather than saved, because a social
 * post generated independently does nothing for the parent's citability.
 */
class RepurposePipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'repurpose';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Repurpose (article → social derivatives)';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [
            LoadParent::class,
            LinkInternally::class,
            GenerateHero::class,
            WritePosts::class,
            SaveDerivatives::class,
        ];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return [
            'content_item_id' => ['sometimes', 'string'],
        ];
    }
}
