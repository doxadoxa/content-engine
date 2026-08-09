<?php

declare(strict_types=1);

namespace App\Social\Exceptions;

use App\Social\InteractionReplySender;
use RuntimeException;

/**
 * A reply the operator asked for did not go out, and here is what to tell them.
 *
 * One exception with a status rather than a family of them, because every
 * caller of {@see InteractionReplySender} is a controller answering a person
 * who is looking at the screen: the only thing that differs between the cases
 * is the sentence and whether the right response is "reload" or "fix it".
 *
 * Nothing here is retried automatically. §4.2 puts a human in this loop, and a
 * reply that quietly retried itself after the operator was told it failed is
 * the second reply into one thread this contour exists to prevent.
 */
final class ReplyRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    /**
     * Somebody else moved the conversation first.
     *
     * 409 rather than 422: nothing the operator typed is wrong, the world
     * changed underneath them, and the only useful next step is reloading.
     */
    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    /** The text, the connection or the budget will not allow it as it stands. */
    public static function refused(string $message): self
    {
        return new self($message, 422);
    }
}
