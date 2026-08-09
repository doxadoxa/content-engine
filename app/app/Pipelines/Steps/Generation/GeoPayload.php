<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Pipelines\Contracts\StepPayload;

/** The GEO layer (§5.3). */
final readonly class GeoPayload implements StepPayload
{
    /**
     * @param  array<string, mixed>  $jsonLd
     * @param  array<string, mixed>  $faqJsonLd
     * @param  list<string>  $quotableBlocks
     */
    public function __construct(
        public array $jsonLd,
        public array $faqJsonLd,
        public array $quotableBlocks,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'json_ld' => $this->jsonLd,
            'faq_json_ld' => $this->faqJsonLd,
            'quotable_blocks' => $this->quotableBlocks,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            jsonLd: $data['json_ld'] ?? [],
            faqJsonLd: $data['faq_json_ld'] ?? [],
            quotableBlocks: array_values(array_map('strval', $data['quotable_blocks'] ?? [])),
        );
    }
}
