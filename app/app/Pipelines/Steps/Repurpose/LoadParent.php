<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Repurpose;

use App\Models\BrandBrief;
use App\Models\Channel;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Pipelines\Steps\Generation\ResolvesUnit;

/**
 * The article the derivatives come from (§8.1).
 *
 * Only a live article: a social post announcing something nobody can click
 * through to is worse than no post, and §1's third differentiator is that a
 * derivative *points at* the parent rather than standing alone.
 */
class LoadParent extends AbstractStep
{
    use ResolvesUnit;

    public static function key(): string
    {
        return 'load_parent';
    }

    public function handle(StepContext $context): StepResult
    {
        $unit = $this->unit($context);

        if (! $unit->state->isLive()) {
            throw new TerminalStepFailure(
                "`{$unit->slug}` is {$unit->state->value}, so there is nothing for a social post to link to yet."
            );
        }

        $brief = BrandBrief::activeFor($context->project);

        if ($brief === null) {
            throw new TerminalStepFailure('This project has no active brand brief.');
        }

        // Which channels to write for: what the plan expected, narrowed to what
        // is actually connected. §8.2 — a new channel must need no new delivery
        // code, only a format.
        //
        // Asked with `takesArticleDerivatives()` rather than `isSocial()`,
        // because the two stopped being the same question when Threads
        // arrived: it is social and refuses cross-posts (§1, fact 3). Its
        // derivatives come through the Derivative band of `social_plan`, which
        // applies the Threads format and the governor.
        $connected = Channel::query()
            ->where('is_enabled', true)
            ->get()
            ->filter(fn (Channel $channel): bool => $channel->type->takesArticleDerivatives())
            ->map(fn (Channel $channel): string => $channel->type->value)
            ->unique()
            ->values()
            ->all();

        $planned = $unit->planned_derivatives;

        /** @var list<string> $channels */
        $channels = $planned === []
            ? $connected
            : array_values(array_intersect($planned, $connected));

        if ($channels === []) {
            throw new TerminalStepFailure(
                'No social channel is connected for this project, so there is nowhere for a derivative to go.'
            );
        }

        return StepResult::success(new ParentPayload(
            unitId: $unit->getKey(),
            title: $unit->title,
            summary: (string) $unit->summary,
            markdown: (string) $unit->body_markdown,
            entities: $unit->entities,
            channels: $channels,
            compiledBrief: $brief->compileToPrompt(),
            locale: $unit->locale,
        ));
    }
}
