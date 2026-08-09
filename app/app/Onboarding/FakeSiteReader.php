<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Onboarding\Contracts\SiteReader;
use RuntimeException;

/** The site reader the suite runs against. */
class FakeSiteReader implements SiteReader
{
    private ?SiteSnapshot $snapshot = null;

    private ?string $failure = null;

    public function willReturn(SiteSnapshot $snapshot): self
    {
        $this->snapshot = $snapshot;

        return $this;
    }

    public function willFail(string $message): self
    {
        $this->failure = $message;

        return $this;
    }

    public function read(string $url): SiteSnapshot
    {
        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        return $this->snapshot ?? new SiteSnapshot(
            url: $url,
            title: 'Cleaning Point — home cleaning in Lisbon',
            description: 'Home cleaning for Lisbon flats, done by people who live here.',
            language: 'pt-PT',
            sitemapUrl: 'https://cleaningpoint.pt/sitemap.xml',
            headings: ['Home cleaning in Lisbon', 'What a visit includes', 'Pricing'],
            links: ['/services', '/pricing', '/about'],
            text: 'We clean flats in Lisbon. Our call-out fee is €45 and covers the first hour.',
        );
    }
}
