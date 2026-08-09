<?php

declare(strict_types=1);

namespace App\Onboarding;

/**
 * What we think the business is, after reading its site.
 *
 * Everything here is a *suggestion*. The wizard shows it back and the operator
 * corrects it — which is why the raw snapshot is kept alongside, so a bad
 * suggestion can be traced to whether the reading or the reasoning was wrong.
 */
final readonly class SiteAnalysis
{
    /**
     * @param  list<string>  $audiences
     * @param  list<string>  $competitors
     * @param  list<string>  $seedKeywords
     * @param  list<string>  $forbidden
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $audiences,
        public string $tone,
        public string $visualLanguage,
        public array $competitors,
        public array $seedKeywords,
        public array $forbidden,
        public string $language,
        public string $market,
        public bool $isYmyl,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'audiences' => $this->audiences,
            'tone' => $this->tone,
            'visual_language' => $this->visualLanguage,
            'competitors' => $this->competitors,
            'seed_keywords' => $this->seedKeywords,
            'forbidden' => $this->forbidden,
            'language' => $this->language,
            'market' => $this->market,
            'is_ymyl' => $this->isYmyl,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $list = static fn (string $key): array => array_values(array_map('strval', $data[$key] ?? []));

        return new self(
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            audiences: $list('audiences'),
            tone: (string) ($data['tone'] ?? ''),
            visualLanguage: (string) ($data['visual_language'] ?? ''),
            competitors: $list('competitors'),
            seedKeywords: $list('seed_keywords'),
            forbidden: $list('forbidden'),
            language: (string) ($data['language'] ?? 'en'),
            market: (string) ($data['market'] ?? 'us'),
            isYmyl: (bool) ($data['is_ymyl'] ?? false),
        );
    }
}
