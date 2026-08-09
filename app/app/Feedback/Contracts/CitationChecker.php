<?php

declare(strict_types=1);

namespace App\Feedback\Contracts;

/**
 * Does the brand show up in an AI answer for this query (§9.3)?
 *
 * The metric the GEO layer of §5.3 exists to move. Until this port existed the
 * whole differentiator was being optimised without a way to tell whether it
 * worked, which is the same position the cheap generators are in.
 */
interface CitationChecker
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @return array<string, bool> assistant name => brand was cited
     */
    public function check(string $query, string $brand): array;
}
