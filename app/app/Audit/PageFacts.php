<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Contracts\PageCheck;

/**
 * One page, reduced to what the checks actually read.
 *
 * The crawler fetches a page once and produces this; every {@see PageCheck}
 * then works from it and never touches the network. That separation is what
 * makes a sweep re-scorable — a check added tomorrow can be run against pages
 * fetched today — and it is why the HTML itself is thrown away rather than
 * stored: nothing downstream needs it, and a hundred pages of somebody else's
 * markup is not ours to keep.
 *
 * Everything here is optional-shaped on purpose. A page that answered 500 has
 * no title, and "no title" is the finding rather than a reason to blow up.
 */
final readonly class PageFacts
{
    /**
     * @param  list<array{level: int, text: string}>  $headings
     * @param  list<string>  $jsonLdTypes  the `@type` of every JSON-LD block that parsed
     * @param  list<array{src: string, alt: string|null, has_dimensions: bool, format: string}>  $images
     * @param  list<string>  $internalLinks  absolute, same-origin, deduplicated
     */
    public function __construct(
        public string $url,
        public ?int $statusCode = null,
        public ?int $responseMs = null,
        public ?int $htmlBytes = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $canonical = null,
        public ?string $lang = null,
        public bool $hasViewport = false,
        /** A `noindex` in the robots meta tag — the page asking not to be found. */
        public bool $isNoIndex = false,
        public array $headings = [],
        public array $jsonLdTypes = [],
        /** A JSON-LD block that is present and does not parse. Worse than none. */
        public bool $hasBrokenJsonLd = false,
        public array $images = [],
        public array $internalLinks = [],
    ) {}

    /** Did the fetch produce a page at all? */
    public function isReadable(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /** @return list<array{level: int, text: string}> */
    public function headingsAtLevel(int $level): array
    {
        return array_values(array_filter(
            $this->headings,
            static fn (array $heading): bool => $heading['level'] === $level,
        ));
    }

    /**
     * The path, for a short label on a screen where the origin is the same on
     * every row.
     */
    public function path(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'status_code' => $this->statusCode,
            'response_ms' => $this->responseMs,
            'html_bytes' => $this->htmlBytes,
            'title' => $this->title,
            'description' => $this->description,
            'canonical' => $this->canonical,
            'lang' => $this->lang,
            'has_viewport' => $this->hasViewport,
            'is_noindex' => $this->isNoIndex,
            'headings' => $this->headings,
            'json_ld_types' => $this->jsonLdTypes,
            'has_broken_json_ld' => $this->hasBrokenJsonLd,
            'images' => $this->images,
            'internal_links' => $this->internalLinks,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            url: (string) ($data['url'] ?? ''),
            statusCode: self::intOrNull($data['status_code'] ?? null),
            responseMs: self::intOrNull($data['response_ms'] ?? null),
            htmlBytes: self::intOrNull($data['html_bytes'] ?? null),
            title: self::stringOrNull($data['title'] ?? null),
            description: self::stringOrNull($data['description'] ?? null),
            canonical: self::stringOrNull($data['canonical'] ?? null),
            lang: self::stringOrNull($data['lang'] ?? null),
            hasViewport: (bool) ($data['has_viewport'] ?? false),
            isNoIndex: (bool) ($data['is_noindex'] ?? false),
            headings: self::headingsFrom($data['headings'] ?? []),
            jsonLdTypes: array_values(array_map('strval', (array) ($data['json_ld_types'] ?? []))),
            hasBrokenJsonLd: (bool) ($data['has_broken_json_ld'] ?? false),
            images: self::imagesFrom($data['images'] ?? []),
            internalLinks: array_values(array_map('strval', (array) ($data['internal_links'] ?? []))),
        );
    }

    /**
     * @return list<array{level: int, text: string}>
     */
    private static function headingsFrom(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $headings = [];

        foreach ($raw as $heading) {
            if (! is_array($heading) || ! isset($heading['level'])) {
                continue;
            }

            $headings[] = [
                'level' => (int) $heading['level'],
                'text' => (string) ($heading['text'] ?? ''),
            ];
        }

        return $headings;
    }

    /**
     * @return list<array{src: string, alt: string|null, has_dimensions: bool, format: string}>
     */
    private static function imagesFrom(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $images = [];

        foreach ($raw as $image) {
            if (! is_array($image) || ! isset($image['src'])) {
                continue;
            }

            $images[] = [
                'src' => (string) $image['src'],
                // Not stringOrNull: an empty alt is `alt=""`, which is the
                // correct marking for a decorative image, and a missing alt is
                // no attribute at all. Collapsing the first into the second
                // reports every site that marks its dividers properly as
                // having nine images with no alt text.
                'alt' => is_string($image['alt'] ?? null) ? (string) $image['alt'] : null,
                'has_dimensions' => (bool) ($image['has_dimensions'] ?? false),
                'format' => (string) ($image['format'] ?? ''),
            ];
        }

        return $images;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
