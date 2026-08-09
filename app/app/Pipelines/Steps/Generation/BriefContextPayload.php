<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Pipelines\Contracts\StepPayload;

/**
 * Everything the writing steps are allowed to know about the brand, compiled
 * once so the outline and the draft cannot disagree about it (§5.1).
 */
final readonly class BriefContextPayload implements StepPayload
{
    /**
     * @param  array<string, mixed>  $originalData  real business facts, or empty
     * @param  array<string, mixed>  $author
     */
    public function __construct(
        public string $briefId,
        public string $compiledBrief,
        public array $originalData,
        public array $author,
        public string $targetQuery,
        public string $locale,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'brief_id' => $this->briefId,
            'compiled_brief' => $this->compiledBrief,
            'original_data' => $this->originalData,
            'author' => $this->author,
            'target_query' => $this->targetQuery,
            'locale' => $this->locale,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            briefId: (string) ($data['brief_id'] ?? ''),
            compiledBrief: (string) ($data['compiled_brief'] ?? ''),
            originalData: $data['original_data'] ?? [],
            author: $data['author'] ?? [],
            targetQuery: (string) ($data['target_query'] ?? ''),
            locale: (string) ($data['locale'] ?? ''),
        );
    }
}
