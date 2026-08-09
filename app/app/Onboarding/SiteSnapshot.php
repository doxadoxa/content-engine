<?php

declare(strict_types=1);

namespace App\Onboarding;

/** What reading a website actually got us, before any model saw it. */
final readonly class SiteSnapshot
{
    /**
     * @param  list<string>  $headings
     * @param  list<string>  $links  internal paths, for guessing what the site is about
     */
    public function __construct(
        public string $url,
        public string $title,
        public string $description,
        public ?string $language,
        public ?string $sitemapUrl,
        public array $headings,
        public array $links,
        public string $text,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->title.$this->description.$this->text) === '';
    }
}
