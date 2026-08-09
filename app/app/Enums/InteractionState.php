<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The life of an incoming reply or mention (§3).
 *
 * Modelled the same way {@see ContentItemState} is, and for the same reason:
 * the allowed edges are the definition of the state rather than a policy over
 * it, so "may this be sent" has one answer everywhere.
 *
 * Two things differ from the content machine. It has terminal states — an
 * answered conversation is finished, and §3 calls this a duty queue rather than
 * a log, so a row that can never leave the queue would be a bug. And `drafted`
 * can go back to `new`: a draft the operator throws away returns the
 * conversation to the queue unanswered, which is honest, where leaving it in
 * `drafted` would quietly hide it behind a reply nobody is going to send.
 */
enum InteractionState: string
{
    /** Just arrived. Nobody has looked at it. */
    case New = 'new';

    /** The engine wrote a reply. Waiting for a human — always (§4.2). */
    case Drafted = 'drafted';

    /** Sent. */
    case Answered = 'answered';

    /** Deliberately left alone, with a reason. */
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Drafted => 'Drafted',
            self::Answered => 'Answered',
            self::Ignored => 'Ignored',
        };
    }

    /**
     * The states this one may become.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Drafted, self::Ignored],
            self::Drafted => [self::Answered, self::Ignored, self::New],
            self::Answered => [],
            self::Ignored => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** True once the conversation is off the operator's list for good. */
    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }
}
