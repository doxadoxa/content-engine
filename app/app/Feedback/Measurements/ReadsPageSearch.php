<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

use App\Feedback\GoogleSearchConsole;
use App\Integrations\Exceptions\GoogleUnavailable;
use App\Models\Project;
use App\Pages\PageUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Search Analytics API: https://developers.google.com/webmaster-tools/v1/searchanalytics/query
 *
 * @phpstan-require-extends GoogleSearchConsole
 */
trait ReadsPageSearch
{
    /** @param list<string> $urls */
    public function pageReport(Project $project, array $urls, Carbon $from, Carbon $to, bool $queries = false): ReadResult
    {
        $metadata = [
            'timezone' => 'America/Los_Angeles', 'data_state' => 'final',
            'search_type' => 'web', 'api_top_rows_only' => true,
            'queries_privacy_filtered' => $queries,
            'dimensions' => $queries ? ['date', 'page', 'query'] : ['date', 'page'],
        ];
        if (! $this->isConfiguredFor($project)) {
            return new ReadResult(ReadStatus::Unavailable, reason: 'Connect Search Console and select an accessible property.', metadata: $metadata);
        }
        $integration = $this->connection->for($project);
        $site = $integration?->searchConsoleSite();
        if ($integration === null || $site === null) {
            return new ReadResult(ReadStatus::Unavailable, reason: 'No Search Console property is selected.', metadata: $metadata);
        }
        $metadata['property'] = $site;
        $rows = [];
        $malformed = 0;
        $wanted = [];
        foreach ($urls as $url) {
            $wanted[PageUrl::normalize($url)] = $url;
        }
        $limit = max(1, min(25000, (int) config('measurements.search_page_size', 25000)));
        $maxPages = max(1, (int) config('measurements.search_max_pages', 10));
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
                        'dimensions' => $metadata['dimensions'], 'type' => 'web',
                        'dataState' => 'final', 'aggregationType' => 'byPage',
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
                    $keys = is_array($row) ? ($row['keys'] ?? []) : [];
                    if (! is_array($keys) || count($keys) !== ($queries ? 3 : 2)
                        || ! is_string($keys[0] ?? null) || ! is_string($keys[1] ?? null)
                        || ! $this->validSearchDay($keys[0], $from, $to)
                        || ! is_numeric($row['impressions'] ?? null) || ! is_numeric($row['clicks'] ?? null)
                        || ($queries && ! is_string($keys[2] ?? null))) {
                        $malformed++;

                        continue;
                    }
                    try {
                        $url = $wanted[PageUrl::normalize($keys[1])] ?? null;
                    } catch (InvalidArgumentException) {
                        $malformed++;

                        continue;
                    }
                    if ($url === null) {
                        continue;
                    }
                    if ((float) $row['impressions'] < 0 || (float) $row['clicks'] < 0) {
                        $malformed++;

                        continue;
                    }
                    $rows[] = new SearchRow(
                        $url, $keys[0], (int) $row['impressions'], (int) $row['clicks'],
                        is_numeric($row['position'] ?? null) && (float) $row['position'] > 0 ? (float) $row['position'] : null,
                        $queries ? $keys[2] : null,
                    );
                }
                if (count($batch) < $limit) {
                    $metadata['malformed_rows'] = $malformed;
                    $metadata['pagination_complete'] = true;
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

    private function validSearchDay(string $day, Carbon $from, Carbon $to): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1
            && checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))
            && $day >= $from->toDateString() && $day <= $to->toDateString();
    }
}
