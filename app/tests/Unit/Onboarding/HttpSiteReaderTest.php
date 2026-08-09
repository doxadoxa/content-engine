<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use App\Onboarding\HttpSiteReader;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HttpSiteReaderTest extends TestCase
{
    #[Test]
    public function it_revalidates_every_redirect_target(): void
    {
        Http::fake([
            'public.example.test/*' => Http::response('', 302, [
                'Location' => 'http://127.0.0.1/internal',
            ]),
        ]);

        $exception = null;

        try {
            app(HttpSiteReader::class)->read('https://public.example.test/start');
        } catch (UnsafePublicUrl $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(UnsafePublicUrl::class, $exception);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_follows_safe_redirects_and_uses_the_final_origin(): void
    {
        Http::fake([
            'public.example.test/start' => Http::response('', 301, [
                'Location' => 'https://www.example.test/home',
            ]),
            'www.example.test/home' => Http::response(
                '<html lang="en"><head><title>Example</title></head><body><h1>Hello</h1></body></html>',
            ),
        ]);

        $snapshot = app(HttpSiteReader::class)->read('https://public.example.test/start');

        $this->assertSame('https://www.example.test/home', $snapshot->url);
        $this->assertSame('https://www.example.test/sitemap.xml', $snapshot->sitemapUrl);
        Http::assertSentCount(2);
    }
}
