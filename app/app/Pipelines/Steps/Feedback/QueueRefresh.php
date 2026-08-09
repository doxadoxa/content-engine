<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Feedback;

use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * Move what decayed into `refreshing` (§9.2).
 *
 * Only the state moves here. The rewrite is the generation pipeline — the same
 * one that wrote the article — because a refresh that used a different code
 * path would drift from it, and `refreshing → draft` is already the edge the
 * state machine has for exactly this.
 *
 * Which means a refreshed article goes back in front of a human before it goes
 * back in front of readers. §1 makes approve-by-default the mitigation for
 * scaled-content risk, and a rewrite is new text.
 */
class QueueRefresh extends AbstractStep
{
    public static function key(): string
    {
        return 'queue_refresh';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [DetectDegradation::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(DetectDegradation::key())) {
            return StepResult::skip('Nothing was measured, so nothing has decayed.');
        }

        $signals = $context->output(DetectDegradation::key(), SignalsPayload::class);

        $moved = [];

        foreach (array_keys($signals->refreshing) as $id) {
            $unit = ContentItem::query()->find($id);

            if ($unit === null || $unit->state !== ContentItemState::Published) {
                // Already being refreshed, or no longer live. Either way this
                // run has nothing to do to it.
                continue;
            }

            $unit->startRefresh();

            $moved[$id] = $signals->refreshing[$id];
        }

        $context->remember('feedback.refreshing', count($moved));

        return StepResult::success(new SignalsPayload($moved, $signals->clusterScores));
    }
}
