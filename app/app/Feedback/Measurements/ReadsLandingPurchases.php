<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

use App\Feedback\GoogleAnalytics;
use App\Integrations\Exceptions\GoogleUnavailable;
use App\Models\Project;
use App\Models\ProjectIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * GA4 Data API, checked against properties.checkCompatibility before reading.
 * https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema
 *
 * @phpstan-require-extends GoogleAnalytics
 */
trait ReadsLandingPurchases
{
    private const PURCHASE_DIMENSIONS = ['date', 'landingPagePlusQueryString', 'sessionDefaultChannelGroup'];

    private const PURCHASE_METRICS = ['sessions', 'ecommercePurchases', 'grossPurchaseRevenue', 'refundAmount', 'purchaseRevenue'];

    /** @param list<string> $urls */
    public function landingPurchases(Project $project, array $urls, Carbon $from, Carbon $to): ReadResult
    {
        $metadata = [
            'supplementary' => true, 'authoritative_purchase_ledger' => false,
            'scope' => 'selected_landing_paths_in_property', 'landing_origin' => null,
            'attribution' => 'Unassigned property-level landing paths and default channel groups',
            'consent_coverage' => 'unknown', 'new_customers' => null,
            'dimensions' => self::PURCHASE_DIMENSIONS, 'metrics' => self::PURCHASE_METRICS,
        ];
        if (! $this->isConfiguredFor($project)) {
            return new ReadResult(ReadStatus::Unavailable, reason: 'Connect Analytics and select an accessible GA4 property.', metadata: $metadata);
        }
        $integration = $this->connection->for($project);
        $property = $integration?->analyticsProperty();
        if ($integration === null || $property === null) {
            return new ReadResult(ReadStatus::Unavailable, reason: 'No Analytics property is selected.', metadata: $metadata);
        }
        $shape = [
            'dimensions' => array_map(static fn (string $name): array => ['name' => $name], self::PURCHASE_DIMENSIONS),
            'metrics' => array_map(static fn (string $name): array => ['name' => $name], self::PURCHASE_METRICS),
        ];
        $rows = [];
        $issues = [];
        $wanted = [];
        foreach ($urls as $url) {
            $wanted[LandingPath::normalize($url)] = true;
        }
        $metadata['selected_path_count'] = count($wanted);
        try {
            $token = $this->connection->accessToken($integration);
            if ($token === null) {
                return new ReadResult(ReadStatus::Unavailable, reason: 'The Google connection needs to be renewed.', metadata: $metadata);
            }
            $compatibility = $this->purchaseRequest($token, $property, $integration, 'checkCompatibility', $shape);
            if ($compatibility instanceof ReadResult) {
                return new ReadResult($compatibility->status, reason: $compatibility->reason, metadata: $metadata);
            }
            $unsupported = $this->unsupportedPurchaseFields($compatibility);
            $metadata['compatibility_checked_at'] = now()->toIso8601String();
            $metadata['unsupported_fields'] = $unsupported;
            if ($unsupported !== []) {
                return new ReadResult(ReadStatus::Incompatible, reason: 'GA4 does not support this landing-page purchase report: '.implode(', ', $unsupported).'.', metadata: $metadata);
            }
            $limit = max(1, min(250000, (int) config('measurements.analytics_page_size', 50000)));
            $maxPages = max(1, (int) config('measurements.analytics_max_pages', 5));
            for ($page = 0; $page < $maxPages; $page++) {
                $body = $this->purchaseRequest($token, $property, $integration, 'runReport', [
                    ...$shape,
                    'dateRanges' => [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]],
                    'orderBys' => array_map(static fn (string $name): array => ['dimension' => ['dimensionName' => $name]], self::PURCHASE_DIMENSIONS),
                    'limit' => $limit, 'offset' => $page * $limit, 'keepEmptyRows' => false,
                ]);
                if ($body instanceof ReadResult) {
                    return new ReadResult($page === 0 ? $body->status : ReadStatus::Partial, $rows, $body->reason, $metadata);
                }
                $reportMeta = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
                foreach (['currencyCode', 'timeZone'] as $setting) {
                    if (isset($metadata[$setting]) && ($reportMeta[$setting] ?? null) !== $metadata[$setting]) {
                        $issues[] = 'GA4 changed its reporting currency or timezone during pagination.';
                    }
                }
                $metadata = [...$metadata, ...$reportMeta];
                if (($reportMeta['subjectToThresholding'] ?? false) || ($reportMeta['dataLossFromOtherRow'] ?? false)
                    || ($reportMeta['samplingMetadatas'] ?? []) !== [] || ($reportMeta['dataTruncationReasons'] ?? []) !== []
                    || data_get($reportMeta, 'schemaRestrictionResponse.activeMetricRestrictions', []) !== []) {
                    $issues[] = 'GA4 reports thresholding, sampling, truncation or restricted metrics.';
                }
                if (! is_string($reportMeta['currencyCode'] ?? null) || preg_match('/^[A-Z]{3}$/', $reportMeta['currencyCode']) !== 1) {
                    $issues[] = 'GA4 did not identify the reporting currency.';
                }
                if (! is_string($reportMeta['timeZone'] ?? null)) {
                    $issues[] = 'GA4 did not identify the reporting timezone.';
                }
                if (array_column($body['dimensionHeaders'] ?? [], 'name') !== self::PURCHASE_DIMENSIONS
                    || array_column($body['metricHeaders'] ?? [], 'name') !== self::PURCHASE_METRICS
                    || ! is_array($body['rows'] ?? []) || ! is_numeric($body['rowCount'] ?? 0)) {
                    return new ReadResult(ReadStatus::Partial, $rows, 'GA4 returned an unexpected report shape.', $metadata);
                }
                $batch = $body['rows'] ?? [];
                if ($batch !== [] && ! isset($body['rowCount'])) {
                    return new ReadResult(ReadStatus::Partial, $rows, 'GA4 omitted its nonempty report row count.', $metadata);
                }
                foreach ($batch as $raw) {
                    $dimensions = is_array($raw) ? array_column($raw['dimensionValues'] ?? [], 'value') : [];
                    $metrics = is_array($raw) ? array_column($raw['metricValues'] ?? [], 'value') : [];
                    if (count($dimensions) !== count(self::PURCHASE_DIMENSIONS) || count($metrics) !== count(self::PURCHASE_METRICS)
                        || count(array_filter($dimensions, 'is_string')) !== count($dimensions)
                        || count(array_filter($metrics, 'is_numeric')) !== count($metrics)) {
                        $issues[] = 'GA4 returned a malformed row.';

                        continue;
                    }
                    [$day, $landing, $channel] = $dimensions;
                    if (preg_match('/^\d{8}$/', $day) !== 1 || ! checkdate((int) substr($day, 4, 2), (int) substr($day, 6, 2), (int) substr($day, 0, 4))) {
                        $issues[] = 'GA4 returned an invalid date.';

                        continue;
                    }
                    $date = substr($day, 0, 4).'-'.substr($day, 4, 2).'-'.substr($day, 6, 2);
                    if ($date < $from->toDateString() || $date > $to->toDateString()) {
                        $issues[] = 'GA4 returned a date outside the requested window.';

                        continue;
                    }
                    if (! str_starts_with($landing, '/') || str_starts_with($landing, '//')) {
                        $metadata['unattributed_rows'] = (int) ($metadata['unattributed_rows'] ?? 0) + 1;

                        continue;
                    }
                    try {
                        $path = LandingPath::normalize($landing);
                    } catch (InvalidArgumentException) {
                        $issues[] = 'GA4 returned an invalid landing URL.';

                        continue;
                    }
                    if (! isset($wanted[$path])) {
                        continue;
                    }
                    $values = array_map(static fn (mixed $value): float => (float) $value, $metrics);
                    if (count(array_filter($values, static fn (float $value): bool => is_finite($value) && abs($value) <= PHP_INT_MAX / 1000000)) !== count($values)
                        || $values[0] < 0 || $values[1] < 0 || $values[2] < 0 || $values[3] < 0) {
                        $issues[] = 'GA4 returned an invalid metric value.';

                        continue;
                    }
                    $rows[] = new AnalyticsRow($path, $date, $channel, (int) $values[0], (int) $values[1],
                        (int) round($values[2] * 1000000), (int) round($values[3] * 1000000), (int) round($values[4] * 1000000),
                        is_string($reportMeta['currencyCode'] ?? null) ? $reportMeta['currencyCode'] : null);
                }
                if (($page * $limit) + count($batch) >= (int) ($body['rowCount'] ?? 0)) {
                    $metadata['pagination_complete'] = true;
                    $metadata['quality_issues'] = array_values(array_unique($issues));
                    if ($issues === []) {
                        $integration->forceFill(['last_synced_at' => now()])->save();
                    }

                    return new ReadResult($issues === [] ? ReadStatus::Complete : ReadStatus::Partial, $rows,
                        $issues === [] ? null : implode(' ', array_unique($issues)), $metadata);
                }
                if (count($batch) < $limit) {
                    return new ReadResult(ReadStatus::Partial, $rows, 'GA4 stopped before its reported row count.', $metadata);
                }
            }
        } catch (ConnectionException|GoogleUnavailable $exception) {
            return new ReadResult($rows === [] ? ReadStatus::Failed : ReadStatus::Partial, $rows, $exception->getMessage(), $metadata);
        }

        return new ReadResult(ReadStatus::Partial, $rows, 'GA4 exceeded the paging limit; totals are incomplete.', [...$metadata, 'pagination_complete' => false]);
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>|ReadResult
     */
    private function purchaseRequest(string $token, string $property, ProjectIntegration $integration, string $method, array $body): array|ReadResult
    {
        $response = Http::withToken($token)->timeout(60)->post("https://analyticsdata.googleapis.com/v1beta/{$property}:{$method}", $body);
        if ($response->status() === 401) {
            $integration->markBroken('Google refused the connection. Reconnect to grant access again.');
        }
        if ($response->failed()) {
            $status = match (true) {
                in_array($response->status(), [401, 403], true) => ReadStatus::Unavailable,
                $response->status() === 400 => ReadStatus::Incompatible,
                default => ReadStatus::Failed,
            };

            return new ReadResult($status, reason: "GA4 {$method} answered {$response->status()}.");
        }
        $data = $response->json();

        return is_array($data) ? $data : new ReadResult(ReadStatus::Failed, reason: 'GA4 returned an unreadable response.');
    }

    /** @param array<string, mixed> $body
     * @return list<string>
     */
    private function unsupportedPurchaseFields(array $body): array
    {
        $compatible = [];
        foreach (['dimensionCompatibilities' => 'dimensionMetadata', 'metricCompatibilities' => 'metricMetadata'] as $key => $meta) {
            foreach (($body[$key] ?? []) as $field) {
                if (($field['compatibility'] ?? null) === 'COMPATIBLE') {
                    $compatible[] = (string) ($field[$meta]['apiName'] ?? '');
                }
            }
        }

        return array_values(array_diff([...self::PURCHASE_DIMENSIONS, ...self::PURCHASE_METRICS], $compatible));
    }
}
