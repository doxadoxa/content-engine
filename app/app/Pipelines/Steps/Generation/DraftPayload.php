<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Pipelines\Contracts\StepPayload;

/** The body, before anything has checked it. */
final readonly class DraftPayload implements StepPayload
{
    /** @param list<string> $imageAnchors heading slugs an image belongs after */
    public function __construct(
        public string $markdown,
        public string $summary,
        public array $imageAnchors = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'markdown' => $this->markdown,
            'summary' => $this->summary,
            'image_anchors' => $this->imageAnchors,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            markdown: (string) ($data['markdown'] ?? ''),
            summary: (string) ($data['summary'] ?? ''),
            imageAnchors: array_values(array_map('strval', $data['image_anchors'] ?? [])),
        );
    }
}
