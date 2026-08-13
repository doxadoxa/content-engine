<?php

declare(strict_types=1);

namespace App\Audit\PageSpeed;

use App\Audit\PageSpeed\Contracts\PageSpeedGateway;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Support\Http\PublicHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google's PageSpeed Insights API: Lighthouse, run on Google's machines.
 *
 * Called directly with `Http` rather than through
 * {@see PublicHttpClient}, because the address being fetched
 * is Google's and is fixed in this file — the client exists to make a URL
 * *somebody typed* safe to fetch, and there is no such URL here. The site being
 * measured never reaches our network stack at all; it is a query parameter, and
 * Google's crawler is the one that visits it.
 *
 * The two failure modes are told apart deliberately. A quota or a 5xx is
 * retryable and the pipeline should back off into it; a rejected key is not,
 * and retrying it three times only spends three times as long arriving at the
 * same answer. Anything else — a page Lighthouse could not render — is null,
 * which the caller stores as "unmeasured".
 */
class GooglePageSpeedInsights implements PageSpeedGateway
{
    private const string ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function name(): string
    {
        return 'pagespeed_insights';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function measure(string $url): ?PageSpeedReading
    {
        if (! $this->isConfigured()) {
            // Not an exception: "no key" is the ordinary state of most
            // installations, and the step asks isConfigured() before it gets
            // here. Reaching this line means somebody called it anyway, and the
            // honest answer is still that there is no reading.
            return null;
        }

        $strategy = (string) config('audit.page_speed.strategy', 'mobile');

        try {
            $response = Http::timeout((int) config('audit.page_speed.timeout', 90))
                ->retry(0)
                ->get(self::ENDPOINT, [
                    'url' => $url,
                    'key' => $this->apiKey(),
                    'strategy' => $strategy,
                    // Only the category we score on. The default asks for every
                    // one of them, which is four Lighthouse runs' worth of work
                    // and a response several megabytes long.
                    'category' => 'performance',
                ]);
        } catch (ConnectionException $e) {
            throw new RetryableStepFailure("PageSpeed Insights could not be reached: {$e->getMessage()}", previous: $e);
        }

        if ($response->status() === 400 || $response->status() === 403) {
            throw new TerminalStepFailure(
                'PageSpeed Insights rejected the request. Check PAGESPEED_API_KEY and that the '
                .'PageSpeed Insights API is enabled for the project it belongs to.'
            );
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new RetryableStepFailure(
                "PageSpeed Insights answered {$response->status()}."
            );
        }

        if ($response->failed()) {
            Log::info('PageSpeed Insights had nothing to say about a page', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $this->readingFrom($response->json(), $strategy);
    }

    private function readingFrom(mixed $body, string $strategy): ?PageSpeedReading
    {
        if (! is_array($body)) {
            return null;
        }

        $score = data_get($body, 'lighthouseResult.categories.performance.score');

        // Lighthouse reports 0–1. Absent means it ran and produced no category,
        // which is a page it could not render rather than a page that scored
        // nothing — so it must not become a zero.
        if (! is_numeric($score)) {
            return null;
        }

        return new PageSpeedReading(
            score: (int) round(((float) $score) * 100),
            largestContentfulPaintMs: $this->millis($body, 'largest-contentful-paint'),
            cumulativeLayoutShift: $this->number($body, 'cumulative-layout-shift'),
            totalBlockingTimeMs: $this->millis($body, 'total-blocking-time'),
            strategy: $strategy,
        );
    }

    private function millis(mixed $body, string $audit): ?int
    {
        $value = data_get($body, "lighthouseResult.audits.{$audit}.numericValue");

        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function number(mixed $body, string $audit): ?float
    {
        $value = data_get($body, "lighthouseResult.audits.{$audit}.numericValue");

        return is_numeric($value) ? round((float) $value, 3) : null;
    }

    private function apiKey(): string
    {
        return trim((string) config('audit.page_speed.api_key', ''));
    }
}
