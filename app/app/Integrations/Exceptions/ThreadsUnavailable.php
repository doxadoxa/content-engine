<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * Threads did not answer, or answered with something that passes. Retryable.
 *
 * Covers the two cases time fixes: the platform being unwell (5xx) and the
 * request budget of §2 being full (429). Neither is repaired by changing the
 * request, and a publish that hits one is a good post at a bad moment.
 */
class ThreadsUnavailable extends RuntimeException
{
    /**
     * @param  bool  $answered  whether the platform sent an HTTP response at all
     */
    public function __construct(string $message, public readonly bool $answered = true)
    {
        parent::__construct($message);
    }

    /**
     * The request left and nothing came back.
     *
     * The distinction this constructor exists for is narrow and expensive. A
     * 429 or a 503 is the platform *answering*: it declined the request, and
     * whatever the request would have done, it did not do. A connection that
     * times out or closes empty is the platform saying nothing, and the request
     * may well have been carried out — we simply never learned the result.
     *
     * For a read that difference is academic. For the second half of a
     * two-phase publish it decides whether retrying is free or posts twice
     * (§9): there is no client-side idempotency key, so re-presenting a
     * `creation_id` that was already consumed is a gamble on undocumented
     * server behaviour.
     */
    public static function noAnswer(string $message): self
    {
        return new self($message, answered: false);
    }
}
