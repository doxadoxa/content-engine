<?php

declare(strict_types=1);

namespace App\Affiliates\Exceptions;

use RuntimeException;

/**
 * Anderro answered, and the answer was not "accepted".
 *
 * Carries the status because the jobs sending events treat the two kinds of
 * refusal oppositely: a 5xx or a 429 is Anderro having a bad minute and is
 * worth another attempt, while any other 4xx is a request that will be refused
 * the same way however many times it is sent — a revoked key, a malformed
 * event — and retrying it only delays somebody noticing.
 */
final class AffiliateEventRejected extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly ?string $reason = null)
    {
        parent::__construct(
            'Anderro refused the event with HTTP '.$status.($reason !== null ? ': '.$reason : '.'),
        );
    }

    public function isPermanent(): bool
    {
        return $this->status >= 400 && $this->status < 500 && $this->status !== 429;
    }
}
