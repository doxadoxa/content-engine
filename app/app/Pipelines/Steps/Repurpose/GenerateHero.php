<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Repurpose;

use App\Media\HeroImage;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Generation\ResolvesUnit;

/**
 * The hero image (§8.3): 1200×630, the size a social card wants.
 *
 * Skipped rather than failed when no provider is configured — a project without
 * an image key should still get its article and its social posts, and §5's
 * out-of-scope note means every unit written before this phase has no hero
 * either.
 *
 * The prompt is built from the brief's visual language rather than from the
 * article's text: an illustration should look like the brand, and the body is
 * about the subject.
 */
class GenerateHero extends AbstractStep
{
    use ResolvesUnit;

    public function __construct(private readonly HeroImage $hero) {}

    public static function key(): string
    {
        return 'generate_hero';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [LoadParent::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $this->hero->isConfigured()) {
            return StepResult::skip('No image provider is configured for this project.');
        }

        $unit = $this->unit($context);
        $parent = $context->output(LoadParent::key(), ParentPayload::class);

        $made = $this->hero->for($unit, $parent->title, $parent->summary);

        if ($made === null) {
            return StepResult::skip('No image provider is configured for this project.');
        }

        return StepResult::success(new HeroPayload($made['asset']->getKey(), $made['cost']));
    }
}
