<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Feedback\Contracts\AnalyticsGateway;
use App\Integrations\Exceptions\GoogleUnavailable;
use App\Integrations\Google\GoogleConnection;
use App\Models\Project;
use App\Models\ProjectIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GA4, through the Data API (§9.1, extended).
 *
 * Reports on `pagePath`, which is a path and not a URL — GA4 has no idea what
 * host it is installed on, so `/blog/post` is all it will ever say. The join
 * back to a unit is therefore path-against-path, and the host our own
 * `public_url` carries is dropped before comparing.
 */
class GoogleAnalytics implements AnalyticsGateway
{
    /** The API's ceiling is 250,000; this is a day-by-page report, not an export. */
    private const int PAGE_SIZE = 50000;

    private const int MAX_PAGES = 5;

    public function __construct(private readonly GoogleConnection $connection) {}

    public function name(): string
    {
        return 'google-analytics';
    }

    public function isConfiguredFor(Project $project): bool
    {
        $integration = $this->connection->for($project);

        return $integration !== null
            && $integration->isUsable()
            && $integration->grants(ProjectIntegration::SCOPE_ANALYTICS)
            && $integration->analyticsProperty() !== null;
    }

    /**
     * @param  list<string>  $urls
     * @return list<UnitEngagement>
     */
    public function engagement(Project $project, array $urls, Carbon $from, Carbon $to): array
    {
        $integration = $this->connection->for($project);
        $property = $integration?->analyticsProperty();

        if ($integration === null || $property === null) {
            return [];
        }

        $token = $this->connection->accessToken($integration);

        if ($token === null) {
            return [];
        }

        // Keyed by path, because that is the only thing GA4 and we both know.
        $wanted = [];

        foreach ($urls as $url) {
            $wanted[$this->pathOf($url)] = $url;
        }

        $rows = [];
        $complete = false;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $batch = $this->report($token, $property, $from, $to, $page * self::PAGE_SIZE, $integration);

            if ($batch === null) {
                return $rows;
            }

            foreach ($batch as $row) {
                $engagement = $this->toEngagement($row, $wanted);

                if ($engagement !== null) {
                    $rows[] = $engagement;
                }
            }

            if (count($batch) < self::PAGE_SIZE) {
                $complete = true;

                break;
            }
        }

        if (! $complete) {
            Log::warning('Analytics rows were truncated at the page limit', [
                'property' => $property,
                'pages' => self::MAX_PAGES,
            ]);
        }

        if ($complete) {
            $integration->forceFill(['last_synced_at' => now()])->save();
        }

        return $rows;
    }

    /**
     * Sessions by channel, social engagement and conversions (§6).
     *
     * One report over `sessionDefaultChannelGroup`, which is GA4's own
     * classification rather than ours. Re-deriving channels from source and
     * medium is the classic way to end up with a number nobody can reconcile
     * against the GA4 screen the operator will open the moment it looks odd.
     *
     * Unpaged, alone among the reads in this class. The dimension has about a
     * dozen values in total, so one request is the whole answer and a truncated
     * page would need a site with more channel groups than GA4 defines.
     *
     * `keyEvents` and not `conversions`: GA4 renamed the metric in 2025 and the
     * old name is being retired. The rename is a rename — same counter, same
     * definition of what the property owner marked as mattering.
     */
    public function audience(Project $project, Carbon $from, Carbon $to): ?ProjectAudience
    {
        $integration = $this->connection->for($project);
        $property = $integration?->analyticsProperty();

        if ($integration === null || $property === null) {
            return null;
        }

        $token = $this->connection->accessToken($integration);

        if ($token === null) {
            return null;
        }

        $rows = $this->runReport($token, $property, $integration, [
            'dateRanges' => [[
                'startDate' => $from->toDateString(),
                'endDate' => $to->toDateString(),
            ]],
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'engagedSessions'],
                ['name' => 'userEngagementDuration'],
                ['name' => 'keyEvents'],
            ],
            'limit' => 100,
            'keepEmptyRows' => false,
        ]);

        if ($rows === null) {
            return null;
        }

        $total = 0;
        $direct = 0;
        $referral = 0;
        $social = 0;
        $socialEngaged = 0;
        $socialSeconds = 0;
        $conversions = 0;

        foreach ($rows as $row) {
            $dimensions = $row['dimensionValues'] ?? null;
            $metrics = $row['metricValues'] ?? null;

            if (! is_array($dimensions) || ! is_array($metrics) || $dimensions === []) {
                continue;
            }

            $group = (string) ($dimensions[0]['value'] ?? '');

            $value = static fn (int $index): int => (int) round(
                (float) ($metrics[$index]['value'] ?? 0)
            );

            $sessions = $value(0);

            // Everything, including the groups §6 does not name. This is the
            // denominator, and a denominator that only counts the channels we
            // are proud of is not one.
            $total += $sessions;
            $conversions += $value(3);

            if ($group === 'Direct') {
                $direct += $sessions;
            }

            if ($group === 'Referral') {
                $referral += $sessions;
            }

            // "Organic Social" and "Paid Social" are two groups and one
            // channel. §6 asks about the share of social traffic, and a
            // boosted post is still the account's audience arriving.
            if (str_contains($group, 'Social')) {
                $social += $sessions;
                $socialEngaged += $value(1);
                $socialSeconds += $value(2);
            }
        }

        $integration->forceFill(['last_synced_at' => now()])->save();

        return new ProjectAudience(
            totalSessions: $total,
            directSessions: $direct,
            referralSessions: $referral,
            socialSessions: $social,
            socialEngagedSessions: $socialEngaged,
            socialEngagementSeconds: $socialSeconds,
            conversions: $conversions,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $wanted
     */
    private function toEngagement(array $row, array $wanted): ?UnitEngagement
    {
        $dimensions = $row['dimensionValues'] ?? null;
        $metrics = $row['metricValues'] ?? null;

        if (! is_array($dimensions) || ! is_array($metrics) || count($dimensions) < 2) {
            return null;
        }

        $date = (string) ($dimensions[0]['value'] ?? '');
        $path = (string) ($dimensions[1]['value'] ?? '');

        $url = $wanted[$this->normalisePath($path)] ?? null;

        // GA4 answers `date` as YYYYMMDD. Anything else is a row we cannot
        // place in time, and one of those must not take the whole fetch down.
        if ($url === null || preg_match('/^\d{8}$/', $date) !== 1) {
            return null;
        }

        $value = static fn (int $index): int => (int) round(
            (float) ($metrics[$index]['value'] ?? 0)
        );

        return new UnitEngagement(
            url: $url,
            measuredOn: Carbon::createFromFormat('Ymd', $date)->startOfDay(),
            sessions: $value(0),
            engagedSessions: $value(1),
            engagementSeconds: $value(2),
        );
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function report(
        string $token,
        string $property,
        Carbon $from,
        Carbon $to,
        int $offset,
        ProjectIntegration $integration,
    ): ?array {
        return $this->runReport($token, $property, $integration, [
            'dateRanges' => [[
                'startDate' => $from->toDateString(),
                'endDate' => $to->toDateString(),
            ]],
            'dimensions' => [['name' => 'date'], ['name' => 'pagePath']],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'engagedSessions'],
                ['name' => 'userEngagementDuration'],
            ],
            // Ordered, because paging is by offset: without a stable sort
            // GA4 is free to return rows in a different order per request,
            // and page two would then repeat and skip rows from page one.
            'orderBys' => [
                ['dimension' => ['dimensionName' => 'date']],
                ['dimension' => ['dimensionName' => 'pagePath']],
            ],
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
            // A day where a page had no sessions is not a reading worth
            // storing; leaving these out keeps the response to rows that
            // actually happened.
            'keepEmptyRows' => false,
        ]);
    }

    /**
     * One report, with the failure taxonomy every caller here shares.
     *
     * 401 ends the connection, 403 is this account losing sight of this
     * property, anything else that failed is transient and thrown. Extracted
     * because {@see audience()} asks a different question of the same API and
     * a second copy of these four branches is a second place for them to drift.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>|null
     */
    private function runReport(
        string $token,
        string $property,
        ProjectIntegration $integration,
        array $body,
    ): ?array {
        $url = "https://analyticsdata.googleapis.com/v1beta/{$property}:runReport";

        try {
            $response = Http::withToken($token)->timeout(60)->post($url, $body);
        } catch (ConnectionException $e) {
            throw new GoogleUnavailable("Analytics did not answer: {$e->getMessage()}");
        }

        if ($response->status() === 401) {
            $integration->markBroken('Google refused the connection. Reconnect to grant access again.');

            return null;
        }

        if ($response->status() === 403) {
            Log::warning('Analytics refused a property', ['property' => $property]);

            return null;
        }

        if ($response->failed()) {
            throw new GoogleUnavailable("Analytics answered {$response->status()}.");
        }

        $rows = $response->json('rows', []);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** GA4 knows a path; our URLs carry a host. Compare the part they share. */
    private function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return $this->normalisePath(is_string($path) ? $path : '/');
    }

    private function normalisePath(string $path): string
    {
        // Query strings are GA4's, not ours: `/post?utm_source=x` is the same
        // article as `/post`, and treating them apart splits one page's numbers.
        $path = rtrim(Str::before($path, '?'), '/');

        return $path === '' ? '/' : $path;
    }
}
