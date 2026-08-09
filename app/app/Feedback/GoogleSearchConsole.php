<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Feedback\Contracts\SearchConsoleGateway;
use App\Integrations\Exceptions\GoogleUnavailable;
use App\Integrations\Google\GoogleConnection;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Visibility\BrandPresence;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Search Console, for real (§9.1).
 *
 * Asks for `[date, page]` and matches the pages against what this project
 * published, rather than filtering server-side: the API accepts only one filter
 * group, so a list of two hundred URLs cannot be expressed as a query. Fetching
 * the site's rows and discarding the ones we do not recognise is both simpler
 * and the only thing that works.
 *
 * `dataState` is left at Google's default — final, settled data. Fresh data
 * restates itself for days, and a refresh queue that acts on a number which
 * later moves is a queue that rewrites articles that were fine.
 */
class GoogleSearchConsole implements SearchConsoleGateway
{
    /** Google's own ceiling per request. */
    private const int PAGE_SIZE = 25000;

    /** Enough for 250,000 rows. Past that, something is wrong with the query. */
    private const int MAX_PAGES = 10;

    /**
     * How many matched brand queries one snapshot keeps.
     *
     * §6 wants the words and not only the number, and it wants them in a jsonb
     * column that is written every day forever. Ranked by impressions and cut
     * here, because the tail of a brand's query set is misspellings and
     * one-off long tails: the fiftieth is not what an operator acts on, and an
     * uncapped list makes the row grow with the account.
     */
    private const int MAX_BRAND_QUERIES = 50;

    public function __construct(private readonly GoogleConnection $connection) {}

    public function name(): string
    {
        return 'google-search-console';
    }

    public function isConfiguredFor(Project $project): bool
    {
        $integration = $this->connection->for($project);

        return $integration !== null
            && $integration->isUsable()
            && $integration->grants(ProjectIntegration::SCOPE_SEARCH_CONSOLE)
            && $integration->searchConsoleSite() !== null;
    }

    /**
     * @param  list<string>  $urls
     * @return list<UnitMetrics>
     */
    public function performance(Project $project, array $urls, Carbon $from, Carbon $to): array
    {
        $integration = $this->connection->for($project);
        $site = $integration?->searchConsoleSite();

        if ($integration === null || $site === null) {
            return [];
        }

        $token = $this->connection->accessToken($integration);

        if ($token === null) {
            return [];
        }

        // Compared without the trailing slash, because a URL is the join
        // between this system and Google's and the two disagree about it
        // constantly — one of them wrote `/post` and the other `/post/`.
        $wanted = [];

        foreach ($urls as $url) {
            $wanted[$this->normalise($url)] = $url;
        }

        $rows = [];
        $complete = false;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $batch = $this->query($token, $site, $from, $to, $page * self::PAGE_SIZE, $integration, ['date', 'page']);

            if ($batch === null) {
                // Refused, not finished. The distinction is the whole point of
                // the timestamp below: an empty answer and a rejected request
                // both return no rows and mean opposite things.
                return $rows;
            }

            foreach ($batch as $row) {
                $metric = $this->toMetric($row, $wanted);

                if ($metric !== null) {
                    $rows[] = $metric;
                }
            }

            // A short page is the last page.
            if (count($batch) < self::PAGE_SIZE) {
                $complete = true;

                break;
            }
        }

        if (! $complete) {
            // Said out loud rather than returned quietly. A truncated read
            // looks exactly like a site whose older pages stopped ranking,
            // which is the conclusion the refresh queue would draw from it.
            Log::warning('Search Console rows were truncated at the page limit', [
                'site' => $site,
                'pages' => self::MAX_PAGES,
                'rows' => count($rows),
            ]);
        }

        // Only after a read that actually happened. A 401 answered with an
        // empty array and a fresh "last read" timestamp is the settings screen
        // telling the operator everything is fine.
        if ($complete) {
            $integration->forceFill(['last_synced_at' => now()])->save();
        }

        return $rows;
    }

    /**
     * Brand demand for the window, sliced by query (§6).
     *
     * Matched here rather than in the request, for the same reason
     * {@see performance()} matches pages here: the API takes one filter group,
     * and "the brand name" is not one expression. A brand is written
     * "Cleaning Point", "cleaningpoint" and "cleaning-point" by the same person
     * in the same week, and a `contains` filter on any one spelling silently
     * drops the other two — which reads on the dashboard as brand demand
     * falling. {@see BrandPresence::namesBrand()} already handles the spacing,
     * the accents and the word boundary, and is the same matcher the LLM
     * visibility sweep scores on. Two definitions of "did they name us" would
     * be two different answers on one screen.
     */
    public function brandDemand(Project $project, Carbon $from, Carbon $to): ?BrandDemand
    {
        $integration = $this->connection->for($project);
        $site = $integration?->searchConsoleSite();

        if ($integration === null || $site === null) {
            return null;
        }

        $token = $this->connection->accessToken($integration);

        if ($token === null) {
            return null;
        }

        $impressions = 0;
        $clicks = 0;
        $queries = [];
        $complete = false;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $batch = $this->query($token, $site, $from, $to, $page * self::PAGE_SIZE, $integration, ['query']);

            if ($batch === null) {
                // Refused. Not "nobody searched for us" — see the contract.
                return null;
            }

            foreach ($batch as $row) {
                $keys = $row['keys'] ?? null;

                if (! is_array($keys) || $keys === []) {
                    continue;
                }

                $term = (string) $keys[0];

                if (! BrandPresence::namesBrand($term, $project->name)) {
                    continue;
                }

                $seen = (int) ($row['impressions'] ?? 0);

                $impressions += $seen;
                $clicks += (int) ($row['clicks'] ?? 0);
                $queries[$term] = ($queries[$term] ?? 0) + $seen;
            }

            if (count($batch) < self::PAGE_SIZE) {
                $complete = true;

                break;
            }
        }

        if (! $complete) {
            // A truncated read understates brand demand, and understated brand
            // demand is indistinguishable from a brand people stopped
            // searching for. Refused rather than reported low: null already
            // means "not measured" in every column this feeds.
            Log::warning('Brand demand was truncated at the page limit', [
                'site' => $site,
                'pages' => self::MAX_PAGES,
            ]);

            return null;
        }

        arsort($queries);

        $integration->forceFill(['last_synced_at' => now()])->save();

        return new BrandDemand(
            impressions: $impressions,
            clicks: $clicks,
            queries: array_slice($queries, 0, self::MAX_BRAND_QUERIES, preserve_keys: true),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $wanted
     */
    private function toMetric(array $row, array $wanted): ?UnitMetrics
    {
        $keys = $row['keys'] ?? null;

        if (! is_array($keys) || count($keys) < 2) {
            return null;
        }

        [$date, $page] = [(string) $keys[0], (string) $keys[1]];

        $url = $wanted[$this->normalise($page)] ?? null;

        if ($url === null) {
            // A page of this site that is not one of ours — the home page, a
            // product page, anything the engine did not write.
            return null;
        }

        return new UnitMetrics(
            url: $url,
            measuredOn: Carbon::parse($date),
            impressions: (int) ($row['impressions'] ?? 0),
            clicks: (int) ($row['clicks'] ?? 0),
            position: isset($row['position']) ? (float) $row['position'] : null,
        );
    }

    /**
     * A page of rows, or null when Google refused — which is not the same as a
     * page with nothing on it.
     *
     * @param  list<string>  $dimensions  `[date, page]` per unit, `[query]` for brand demand
     * @return list<array<string, mixed>>|null
     */
    private function query(
        string $token,
        string $site,
        Carbon $from,
        Carbon $to,
        int $startRow,
        ProjectIntegration $integration,
        array $dimensions,
    ): ?array {
        // The property name goes in the path and can be `sc-domain:example.com`
        // or a full URL, so it has to be encoded rather than concatenated.
        $url = 'https://www.googleapis.com/webmasters/v3/sites/'
            .rawurlencode($site).'/searchAnalytics/query';

        try {
            $response = Http::withToken($token)->timeout(60)->post($url, [
                'startDate' => $from->toDateString(),
                'endDate' => $to->toDateString(),
                'dimensions' => $dimensions,
                'type' => 'web',
                'rowLimit' => self::PAGE_SIZE,
                'startRow' => $startRow,
            ]);
        } catch (ConnectionException $e) {
            throw new GoogleUnavailable("Search Console did not answer: {$e->getMessage()}");
        }

        if ($response->status() === 401) {
            $integration->markBroken('Google refused the connection. Reconnect to grant access again.');

            return null;
        }

        if ($response->status() === 403) {
            // This account can no longer see this property. Narrower than a
            // lost grant, and the operator fixes it in Search Console rather
            // than by reconnecting here.
            Log::warning('Search Console refused a property', ['site' => $site]);

            return null;
        }

        if ($response->failed()) {
            throw new GoogleUnavailable("Search Console answered {$response->status()}.");
        }

        $rows = $response->json('rows', []);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Trailing slashes and host casing are not differences worth having; the
     * path's casing is. `/Blog` and `/blog` can be two different pages on the
     * same site, and folding them together attributes one page's numbers to
     * the other.
     */
    private function normalise(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return rtrim($url, '/');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');

        return "{$scheme}://{$host}{$path}";
    }
}
