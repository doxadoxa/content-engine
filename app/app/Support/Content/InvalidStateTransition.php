<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Enums\ContentItemState;
use App\Enums\InteractionState;
use RuntimeException;

/**
 * A content unit was asked to move somewhere it cannot go.
 *
 * This is a bug in the caller every time it is thrown, never a user error: the
 * pipeline steps of phase 3 each know which state they operate on, and a step
 * finding a unit in the wrong one means two workers picked up the same unit.
 * Failing loudly is what keeps that from silently publishing a draft.
 *
 * The duty queue of §3 throws the same exception rather than one of its own.
 * Two machines, one failure mode: "something moved a row somewhere the map does
 * not go", and a caller that wants to catch it should not have to know which
 * table it was reading.
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

    /**
     * The same refusal, for a conversation in the duty queue (§3, §4.2).
     *
     * Unlike the content machine this one has terminal states, so "nothing" is
     * a real answer here rather than a guard against an impossible case: an
     * answered conversation is finished, and re-drafting a reply that was
     * already sent is the mistake the message has to name.
     */
    public static function betweenInteractionStates(InteractionState $from, InteractionState $to): self
    {
        $allowed = array_map(
            static fn (InteractionState $state): string => $state->value,
            $from->allowedNext(),
        );

        return new self(sprintf(
            'An interaction cannot go from %s to %s. Allowed from %s: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing' : implode(', ', $allowed),
        ));
    }
}
