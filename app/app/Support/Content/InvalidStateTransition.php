<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Enums\ContentItemState;
use RuntimeException;

/**
 * A content unit was asked to move somewhere it cannot go.
 *
 * This is a bug in the caller every time it is thrown, never a user error: the
 * pipeline steps of phase 3 each know which state they operate on, and a step
 * finding a unit in the wrong one means two workers picked up the same unit.
 * Failing loudly is what keeps that from silently publishing a draft.
 */
final class InvalidStateTransition extends RuntimeException
{
    public static function between(ContentItemState $from, ContentItemState $to): self
    {
        $allowed = array_map(
            static fn (ContentItemState $state): string => $state->value,
            $from->allowedNext(),
        );

        return new self(sprintf(
            'A content item cannot go from %s to %s. Allowed from %s: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing' : implode(', ', $allowed),
        ));
    }
}
