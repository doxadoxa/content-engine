<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Repurpose;

use App\Pipelines\Contracts\StepPayload;

/** What the derivatives inherit (§8.1). */
final readonly class ParentPayload implements StepPayload
{
    /**
     * @param  list<string>  $entities
     * @param  list<string>  $channels  channel type values to write for
     */
    public function __construct(
        public string $unitId,
        public string $title,
        public string $summary,
        public string $markdown,
        public array $entities,
        public array $channels,
        public string $compiledBrief,
        public string $locale,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'unit_id' => $this->unitId,
            'title' => $this->title,
            'summary' => $this->summary,
            'markdown' => $this->markdown,
            'entities' => $this->entities,
            'channels' => $this->channels,
            'compiled_brief' => $this->compiledBrief,
            'locale' => $this->locale,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            unitId: (string) ($data['unit_id'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            summary: (string) ($data['summary'] ?? ''),
            markdown: (string) ($data['markdown'] ?? ''),
            entities: array_values(array_map('strval', $data['entities'] ?? [])),
            channels: array_values(array_map('strval', $data['channels'] ?? [])),
            compiledBrief: (string) ($data['compiled_brief'] ?? ''),
            locale: (string) ($data['locale'] ?? ''),
        );
    }
}
