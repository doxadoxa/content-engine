<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Pipelines\Contracts\StepPayload;

/**
 * What the first step learned about the site, and the audit row it opened.
 *
 * The sitemap's URLs travel in here rather than being re-fetched by the crawler:
 * two steps asking somebody else's server for the same file is one request too
 * many, and it would also let the two disagree about which pages the sweep is
 * about.
 */
final readonly class SiteSignalsPayload implements StepPayload
{
    /**
     * @param  list<string>  $urls  the pages the sitemap named, already origin-checked
     */
    public function __construct(
        public string $auditId,
        public string $origin,
        public array $urls = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'audit_id' => $this->auditId,
            'origin' => $this->origin,
            'urls' => $this->urls,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            auditId: (string) ($data['audit_id'] ?? ''),
            origin: (string) ($data['origin'] ?? ''),
            urls: array_values(array_map('strval', (array) ($data['urls'] ?? []))),
        );
    }
}
