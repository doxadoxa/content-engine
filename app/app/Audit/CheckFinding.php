<?php

declare(strict_types=1);

namespace App\Audit;

use App\Enums\AuditSeverity;

/**
 * One thing a check found wrong.
 *
 * A check returns a list of these, and an empty list is the normal, healthy
 * answer — there is no "passed" object, because a pass has nothing to say and a
 * screen that renders one row per pass buries the four rows that matter.
 *
 * `detail` is whatever the check wants an operator to see beside the summary:
 * the length it measured, the URL that 404ed, the images with no alt text. It
 * reaches the screen as-is, so keep it small and keep it about this finding.
 */
final readonly class CheckFinding
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public AuditSeverity $severity,
        public string $summary,
        public array $detail = [],
    ) {}

    /** @param array<string, mixed> $detail */
    public static function high(string $summary, array $detail = []): self
    {
        return new self(AuditSeverity::High, $summary, $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function medium(string $summary, array $detail = []): self
    {
        return new self(AuditSeverity::Medium, $summary, $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function low(string $summary, array $detail = []): self
    {
        return new self(AuditSeverity::Low, $summary, $detail);
    }
}
