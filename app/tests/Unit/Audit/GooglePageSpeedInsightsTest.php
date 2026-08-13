<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Audit\PageSpeed\GooglePageSpeedInsights;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading Lighthouse's answer.
 *
 * Two things worth holding. Lighthouse reports its category 0–1 and the rest of
 * the product works in 0–100, so the conversion is the whole point of the
 * parser — and an *absent* category is a page it could not render, which must
 * not become a zero. The other is the split between a quota (retryable, the
 * pipeline should back off into it) and a rejected key (terminal, retrying it
 * three times only takes three times as long to reach the same answer).
 */
final class GooglePageSpeedInsightsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('audit.page_speed.api_key', 'test-key');
        config()->set('audit.page_speed.strategy', 'mobile');
    }

    #[Test]
    public function it_is_unconfigured_without_a_key(): void
    {
        config()->set('audit.page_speed.api_key', '   ');

        $gateway = new GooglePageSpeedInsights;

        // The ordinary state of most installations, and every caller has to
        // treat it as "no speed score" rather than as an outage.
        $this->assertFalse($gateway->isConfigured());
        $this->assertNull($gateway->measure('https://example.test/'));
    }

    #[Test]
    public function it_converts_the_category_to_a_hundred_point_scale(): void
    {
        Http::fake([
            '*' => Http::response([
                'lighthouseResult' => [
                    'categories' => ['performance' => ['score' => 0.87]],
                    'audits' => [
                        'largest-contentful-paint' => ['numericValue' => 2410.7],
                        'cumulative-layout-shift' => ['numericValue' => 0.0234],
                        'total-blocking-time' => ['numericValue' => 190],
                    ],
                ],
            ]),
        ]);

        $reading = (new GooglePageSpeedInsights)->measure('https://example.test/');

        $this->assertNotNull($reading);
        $this->assertSame(87, $reading->score);
        $this->assertSame(2411, $reading->largestContentfulPaintMs);
        $this->assertSame(0.023, $reading->cumulativeLayoutShift);
        $this->assertSame(190, $reading->totalBlockingTimeMs);
        $this->assertSame('mobile', $reading->strategy);
    }

    #[Test]
    public function a_result_with_no_performance_category_is_unmeasured_rather_than_zero(): void
    {
        Http::fake([
            '*' => Http::response(['lighthouseResult' => ['categories' => []]]),
        ]);

        // A page Lighthouse could not render scored nothing because it never
        // ran, and a zero here would report it as the slowest page on the site.
        $this->assertNull((new GooglePageSpeedInsights)->measure('https://example.test/'));
    }

    #[Test]
    public function a_rejected_key_fails_terminally(): void
    {
        Http::fake(['*' => Http::response(['error' => 'bad key'], 403)]);

        $this->expectException(TerminalStepFailure::class);

        (new GooglePageSpeedInsights)->measure('https://example.test/');
    }

    #[Test]
    public function a_quota_or_an_outage_is_worth_retrying(): void
    {
        Http::fake(['*' => Http::response('', 429)]);

        $this->expectException(RetryableStepFailure::class);

        (new GooglePageSpeedInsights)->measure('https://example.test/');
    }

    #[Test]
    public function it_asks_only_for_the_category_it_scores_on(): void
    {
        Http::fake([
            '*' => Http::response([
                'lighthouseResult' => ['categories' => ['performance' => ['score' => 1]]],
            ]),
        ]);

        (new GooglePageSpeedInsights)->measure('https://example.test/');

        // The default asks for every category, which is four Lighthouse runs of
        // work and a response several megabytes long.
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'category=performance'));
    }
}
