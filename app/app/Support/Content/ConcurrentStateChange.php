<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Enums\InteractionState;
use RuntimeException;

/**
 * A state change was prepared against a row somebody else has since moved.
 *
 * The sibling of {@see InvalidStateTransition}, and the distinction between the
 * two is the whole reason this class exists. That one means the map has no such
 * edge — a bug in the caller, decidable from the model in hand. This one means
 * the edge was legal when the caller looked and the row had moved on by the
 * time it wrote: two workers, one row, and nothing wrong with either of them
 * except the order they finished in.
 *
 * The case it was written for is the one §4.2 exists to prevent. A drafting job
 * reads a conversation in `new` and spends twenty seconds in a model call; the
 * operator answers it meanwhile; the job comes back and writes `drafted` over
 * an `answered` row, whose `answered_at` and `reply_external_id` survive
 * untouched. The conversation reappears in the duty queue carrying a fresh
 * draft and a second reply goes into the same thread.
 *
 * The message names that, rather than saying "conflict": the operator reading
 * it in a failed job has to be able to tell "your reply won, the draft was
 * thrown away" from a real fault.
 */
final class ConcurrentStateChange extends RuntimeException
{
    /**
     * @param  InteractionState  $expected  what the caller had in hand
     * @param  InteractionState  $attempted  where it was trying to go
     * @param  InteractionState|null  $actual  what the row says now, or null if it is gone
     */
    public static function forInteraction(
        string $id,
        InteractionState $expected,
        InteractionState $attempted,
        ?InteractionState $actual,
    ): self {
        return new self(sprintf(
            'Interaction %s was %s when the move to %s was prepared and is %s now, so the write was refused. %s',
            $id,
            $expected->value,
            $attempted->value,
            $actual->value ?? 'no longer there',
            self::explain($attempted, $actual),
        ));
    }

    /** The same failure in words an operator can act on. */
    private static function explain(InteractionState $attempted, ?InteractionState $actual): string
    {
        if ($actual === null) {
            return 'The conversation was deleted while the change was being prepared.';
        }

        if ($actual === InteractionState::Answered && $attempted === InteractionState::Drafted) {
            return 'This conversation was answered while a reply was being drafted. '
                .'The draft has been dropped rather than sent as a second reply into the same thread.';
        }

        if ($actual->isTerminal()) {
            return sprintf(
                'This conversation was already closed as %s while the change was being prepared, and %s is finished.',
                $actual->value,
                $actual->value,
            );
        }

        return 'Somebody else moved this conversation while the change was being prepared. '
            .'Reload it and decide again.';
    }
}
