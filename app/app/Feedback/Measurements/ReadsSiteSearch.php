<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

use App\Feedback\GoogleSearchConsole;
use App\Integrations\Exceptions\GoogleUnavailable;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Whole-property Search Analytics: https://developers.google.com/webmaster-tools/v1/searchanalytics/query
 *
 * The same error semantics as {@see ReadsPageSearch}, on purpose: a 401 breaks
 * the connection, a refusal is Unavailable rather than an empty site, and a row
 * that cannot be read makes the report Partial — because a Partial report is
 * never allowed to replace a complete one, and a site whose numbers quietly
 * halved because one row was malformed is exactly the thing that must not be
 * stored.
 *
 * @phpstan-require-extends GoogleSearchConsole
 */
trait ReadsSiteSearch
{
    /** Top rows per ranking list when the caller does not say. */
    private const int SITE_ROW_LIMIT = 250;

    public function siteReport(Project $project, Carbon $from, Carbon $to, string $dimension, ?int $rowLimit = null, ?string $property = null): ReadResult
    {
        if (! in_array($dimension, ['date', 'query', 'page'], true)) {
            throw new InvalidArgumentException("Unsupported site report dimension [{$dimension}].");
        }
        $ranked = $dimension !== 'date';
        $limit = $ranked
            ? max(1, min(25000, $rowLimit ?? self::SITE_ROW_LIMIT))
            : max(1, min(25000, (int) config('measurements.search_page_size', 25000)));
        $maxPages = $ranked ? 1 : max(1, (int) config('measurements.search_max_pages', 10));
        // Google only accepts byPage when the page dimension is requested. For
        // a date or query report byProperty is also the number Search Console's
        // own UI shows as the site total, so the two agree.
        $aggregation = $dimension === 'page' ? 'byPage' : 'byProperty';
        $metadata = [
            'timezone' => 'America/Los_Angeles', 'data_state' => 'final',
            'search_type' => 'web', 'scope' => 'property',
            'dimensions' => [$dimension], 'aggregation_type' => $aggregation,
            'api_top_rows_only' => $ranked, 'row_limit' => $ranked ? $limit : null,
            'queries_privacy_filtered' => $dimension === 'query',
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
        ];
        if (! $this->isConfiguredFor($project)) {
            return new ReadResult(ReadStatus::Unavailable, reason: 'Connect Search Console and select an accessible property.', metadata: $metadata);
        }
        $integration = $this->connection->for($project);
        $site = $property ?? $integration?->searchConsoleSite();
        if ($integration === null || $site === null) {
            return new ReadResult(ReadStatus::Unavailable, reason: 'No Search Console property is selected.', metadata: $metadata);
        }
        $metadata['property'] = $site;
        $rows = [];
        $malformed = 0;
        $skipped = 0;
        try {
            $token = $this->connection->accessToken($integration);
            if ($token === null) {
                return new ReadResult(ReadStatus::Unavailable, reason: 'The Google connection needs to be renewed.', metadata: $metadata);
            }
            for ($page = 0; $page < $maxPages; $page++) {
                $response = Http::withToken($token)->timeout(60)->post(
                    'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($site).'/searchAnalytics/query',
                    [
                        'startDate' => $from->toDateString(), 'endDate' => $to->toDateString(),
                        'dimensions' => [$dimension], 'type' => 'web',
                        'dataState' => 'final', 'aggregationType' => $aggregation,
                        'rowLimit' => $limit, 'startRow' => $page * $limit,
                    ],
                );
                if ($response->status() === 401) {
                    $integration->markBroken('Google refused the connection. Reconnect to grant access again.');
                }
                if ($response->failed()) {
                    $status = $page > 0 ? ReadStatus::Partial : (in_array($response->status(), [401, 403], true) ? ReadStatus::Unavailable : ReadStatus::Failed);

                    return new ReadResult($status, $rows, 'Search Console answered '.$response->status().'.', $metadata);
                }
                $body = $response->json();
                if (! is_array($body) || (isset($body['rows']) && ! is_array($body['rows']))) {
                    return new ReadResult(ReadStatus::Partial, $rows, 'Search Console returned an unreadable report.', $metadata);
                }
                $batch = $body['rows'] ?? [];
                foreach ($batch as $row) {
                    if (is_array($row) && $this->notOurs($row, $dimension)) {
                        $skipped++;

                        continue;
                    }
                    $parsed = is_array($row) ? $this->siteRow($row, $dimension, $from, $to) : null;
                    if ($parsed === null) {
                        $malformed++;

                        continue;
                    }
                    $rows[] = $parsed;
                }
                // A ranking list is one request by design: the top N is the
                // answer, not the first page of it.
                if ($ranked || count($batch) < $limit) {
                    $metadata['malformed_rows'] = $malformed;
                    $metadata['skipped_rows'] = $skipped;
                    $metadata['pagination_complete'] = true;
                    if ($ranked) {
                        $metadata['reached_row_limit'] = count($batch) >= $limit;
                    }
                    if ($malformed === 0) {
                        $integration->forceFill(['last_synced_at' => now()])->save();
                    }

                    return new ReadResult($malformed === 0 ? ReadStatus::Complete : ReadStatus::Partial, $rows,
                        $malformed === 0 ? null : 'Some Search Console rows were malformed; the report is incomplete.', $metadata);
                }
            }
        } catch (ConnectionException|GoogleUnavailable $exception) {
            return new ReadResult($rows === [] ? ReadStatus::Failed : ReadStatus::Partial, $rows, $exception->getMessage(), $metadata);
        }

        return new ReadResult(ReadStatus::Partial, $rows, 'Search Console exceeded the paging limit; totals are incomplete.', [...$metadata, 'pagination_complete' => false]);
    }

    /**
     * A well-formed row this product has no use for, rather than a broken one:
     * a page key that is not a web URL (an app deep link, an AMP cache path)
     * or a query that is only whitespace. Counted, and skipped without making
     * an otherwise complete report Partial — Partial would stop it ever being
     * stored.
     *
     * @param  array<mixed>  $row
     */
    private function notOurs(array $row, string $dimension): bool
    {
        $keys = $row['keys'] ?? null;
        $key = is_array($keys) && count($keys) === 1 ? ($keys[0] ?? null) : null;
        if (! is_string($key)) {
            return false;
        }

        return match ($dimension) {
            'page' => trim($key) !== '' && ! in_array(strtolower((string) parse_url($key, PHP_URL_SCHEME)), ['http', 'https'], true),
            'query' => trim($key) === '',
            default => false,
        };
    }

    /** @param array<mixed> $row */
    private function siteRow(array $row, string $dimension, Carbon $from, Carbon $to): ?SiteSearchRow
    {
        $keys = $row['keys'] ?? null;
        if (! is_array($keys) || count($keys) !== 1 || ! is_string($keys[0] ?? null) || trim($keys[0]) === ''
            || ! is_numeric($row['impressions'] ?? null) || ! is_numeric($row['clicks'] ?? null)
            || (float) $row['impressions'] < 0 || (float) $row['clicks'] < 0) {
            return null;
        }
        $key = $keys[0];
        if ($dimension === 'date' && ! $this->validSiteDay($key, $from, $to)) {
            return null;
        }
        if ($dimension === 'page' && ! in_array(strtolower((string) parse_url($key, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }
        $position = is_numeric($row['position'] ?? null) && (float) $row['position'] > 0 ? (float) $row['position'] : null;

        return new SiteSearchRow(
            $dimension === 'date' ? $key : null,
            $dimension === 'date' ? null : $key,
            (int) $row['impressions'], (int) $row['clicks'], $position,
        );
    }

    private function validSiteDay(string $day, Carbon $from, Carbon $to): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1
            && checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))
            && $day >= $from->toDateString() && $day <= $to->toDateString();
    }
}
