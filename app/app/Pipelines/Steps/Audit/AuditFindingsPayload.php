<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Pipelines\Contracts\StepPayload;

/**
 * How many findings one of the three inspecting branches wrote.
 *
 * A count rather than the findings: they are already rows by the time this is
 * returned, and {@see ScoreAudit} reads all three branches' work from the table
 * rather than from three payloads it would have to merge. What this carries is
 * enough for a step's output to be readable in the run log — "verify_links
 * wrote 4" is the line somebody wants when a score moves and nobody knows why.
 */
final readonly class AuditFindingsPayload implements StepPayload
{
    public function __construct(
        public string $auditId,
        public int $findings = 0,
        /** What the branch actually looked at: pages, links, or measured pages. */
        public int $examined = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'audit_id' => $this->auditId,
            'findings' => $this->findings,
            'examined' => $this->examined,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            auditId: (string) ($data['audit_id'] ?? ''),
            findings: (int) ($data['findings'] ?? 0),
            examined: (int) ($data['examined'] ?? 0),
        );
    }
}
