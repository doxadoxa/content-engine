<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Models\ContentItem;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Exceptions\TerminalStepFailure;

/**
 * Every generation step is about one unit, and the run says which.
 *
 * Read from the run rather than passed between steps: the unit is a row that
 * other steps are writing to, and a copy carried through payloads would be
 * stale by the time the fan-in read it.
 */
trait ResolvesUnit
{
    protected function unit(StepContext $context): ContentItem
    {
        $id = $context->run->content_item_id ?? $context->get('content_item_id');

        if (! is_string($id) || $id === '') {
            throw new TerminalStepFailure(
                'This run is not about a content unit. Start it with a content_item_id.'
            );
        }

        $unit = ContentItem::query()->find($id);

        if ($unit === null) {
            throw new TerminalStepFailure("Content unit `{$id}` is not in this project.");
        }

        return $unit;
    }
}
