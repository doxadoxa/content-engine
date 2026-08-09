<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * Threads understood the request and said no. Not retryable.
 *
 * A segment over 500 characters, a container id that has already been
 * published, a scope the app was never granted. All of these will be refused in
 * exactly the same way in twelve hours, and walking the backoff ladder over
 * them spends the daily action budget of §2 to arrive at the same answer.
 */
class ThreadsRefused extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
