<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Pipelines\Contracts\StepPayload;

/**
 * What the crawl produced: the audit it belongs to, and how much of the site it
 * managed to read.
 *
 * The pages themselves are rows, not payload. Three steps read them and one of
 * them reads every link on every page — carrying that through a json column
 * would put a copy of the crawl into the step output of each.
 */
final readonly class CrawledPagesPayload implements StepPayload
{
    public function __construct(
        public string $auditId,
        public string $origin,
        public int $crawled = 0,
        public int $unreachable = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'audit_id' => $this->auditId,
            'origin' => $this->origin,
            'crawled' => $this->crawled,
            'unreachable' => $this->unreachable,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            auditId: (string) ($data['audit_id'] ?? ''),
            origin: (string) ($data['origin'] ?? ''),
            crawled: (int) ($data['crawled'] ?? 0),
            unreachable: (int) ($data['unreachable'] ?? 0),
        );
    }
}
