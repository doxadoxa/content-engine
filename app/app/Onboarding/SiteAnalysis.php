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
     * @param  array{fill: string, ink: string, accent: string|null}|null  $palette
     *                                                                               colours counted off a picture of the site, where one could be taken
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
        public ?array $palette = null,
    ) {}

    /**
     * The same analysis with the site's colours attached.
     *
     * A `with`-er rather than a constructor argument at each call site, because
     * the palette is taken by a different mechanism at a different time from
     * everything else here — a browser rather than a model — and it has to
     * reach both the interpreted analysis and the blank one a dead site
     * produces.
     *
     * @param  array{fill: string, ink: string, accent: string|null}|null  $palette
     */
    public function withPalette(?array $palette): self
    {
        return new self(
            name: $this->name,
            description: $this->description,
            audiences: $this->audiences,
            tone: $this->tone,
            visualLanguage: $this->visualLanguage,
            competitors: $this->competitors,
            seedKeywords: $this->seedKeywords,
            forbidden: $this->forbidden,
            language: $this->language,
            market: $this->market,
            isYmyl: $this->isYmyl,
            palette: $palette,
        );
    }

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
            'palette' => $this->palette,
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
            // Absent on every project analysed before there was a browser to
            // take the picture with, which is a suggestion nobody was offered
            // rather than a suggestion that failed.
            palette: is_array($data['palette'] ?? null) ? [
                'fill' => (string) ($data['palette']['fill'] ?? ''),
                'ink' => (string) ($data['palette']['ink'] ?? ''),
                'accent' => isset($data['palette']['accent'])
                    ? (string) $data['palette']['accent']
                    : null,
            ] : null,
        );
    }
}
