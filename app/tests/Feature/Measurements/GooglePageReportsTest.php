<?php

declare(strict_types=1);

namespace Tests\Feature\Measurements;

use App\Feedback\GoogleAnalytics;
use App\Feedback\GoogleSearchConsole;
use App\Feedback\Measurements\AnalyticsRow;
use App\Feedback\Measurements\ReadStatus;
use App\Feedback\Measurements\SearchRow;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GooglePageReportsTest extends TestCase
{
    use RefreshDatabase;

    private const DIMS = ['date', 'landingPagePlusQueryString', 'sessionDefaultChannelGroup'];

    private const METRICS = ['sessions', 'ecommercePurchases', 'grossPurchaseRevenue', 'refundAmount', 'purchaseRevenue'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google', ['client_id' => 'id', 'client_secret' => 'secret', 'redirect' => 'http://localhost/callback']);
        Http::preventStrayRequests();
    }

    #[Test]
    public function search_reads_final_daily_page_query_history_and_preserves_meaningful_url_identity(): void
    {
        $project = $this->connected();
        Http::fake(['www.googleapis.com/*' => Http::response(['rows' => [
            ['keys' => ['2026-08-01', 'https://EXAMPLE.com/Services/?service=clean&utm_source=google', 'cleaning lisbon'], 'impressions' => 20, 'clicks' => 2, 'position' => 8.5],
            ['keys' => ['2026-08-01', 'https://example.com/Services/?service=other', 'other cleaning'], 'impressions' => 30, 'clicks' => 3, 'position' => 5],
            ['keys' => ['2026-08-01', 'https://other.example/Services/?service=clean', 'cleaning lisbon'], 'impressions' => 40, 'clicks' => 4, 'position' => 4],
        ]])]);
        $report = app(GoogleSearchConsole::class)->pageReport($project, ['https://example.com/Services/?service=clean'], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'), true);
        $this->assertSame(ReadStatus::Complete, $report->status);
        $this->assertCount(1, $report->rows);
        $this->assertInstanceOf(SearchRow::class, $report->rows[0]);
        $this->assertSame('cleaning lisbon', $report->rows[0]->query);
        $this->assertTrue($report->metadata['queries_privacy_filtered']);
        $this->assertTrue($report->metadata['api_top_rows_only']);
        Http::assertSent(fn (Request $request): bool => $request['dimensions'] === ['date', 'page', 'query'] && $request['dataState'] === 'final' && $request['aggregationType'] === 'byPage');
    }

    #[Test]
    public function search_paging_refusal_is_partial_and_never_a_complete_lower_total(): void
    {
        $project = $this->connected();
        config()->set('measurements.search_page_size', 1);
        Http::fake(['www.googleapis.com/*' => Http::sequence()->push(['rows' => [
            ['keys' => ['2026-08-01', 'https://example.com/a'], 'impressions' => 20, 'clicks' => 2, 'position' => 8],
        ]])->push([], 403)]);
        $report = app(GoogleSearchConsole::class)->pageReport($project, ['https://example.com/a'], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'));
        $this->assertSame(ReadStatus::Partial, $report->status);
        $this->assertCount(1, $report->rows);
        Http::assertSent(fn (Request $request): bool => $request['startRow'] === 1);
    }

    #[Test]
    public function missing_google_credentials_make_both_reports_unavailable_without_http(): void
    {
        $project = Project::factory()->create();
        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-08-28');
        $this->assertSame(ReadStatus::Unavailable, app(GoogleSearchConsole::class)->pageReport($project, [], $from, $to)->status);
        $this->assertSame(ReadStatus::Unavailable, app(GoogleAnalytics::class)->landingPurchases($project, [], $from, $to)->status);
        Http::assertNothingSent();
    }

    #[Test]
    public function analytics_checks_compatibility_then_reads_unassigned_property_paths_with_no_claimed_origin(): void
    {
        $project = $this->connected();
        Http::fake([
            '*:checkCompatibility' => Http::response($this->compatible()),
            '*:runReport' => Http::response($this->report([
                $this->row('20260801', '/service?utm_source=ad'),
                $this->row('20260801', '/unselected'),
                $this->row('20260801', '/service?variant=other'),
            ])),
        ]);
        $result = app(GoogleAnalytics::class)->landingPurchases($project, ['https://example.com/service'], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'));
        $this->assertSame(ReadStatus::Complete, $result->status);
        $this->assertCount(1, $result->rows);
        $this->assertInstanceOf(AnalyticsRow::class, $result->rows[0]);
        $this->assertSame(2, $result->rows[0]->purchases);
        $this->assertSame('Organic Search', $result->rows[0]->channelGroup);
        $this->assertSame('EUR', $result->rows[0]->currency);
        $this->assertSame('/service', $result->rows[0]->path);
        $this->assertNull($result->metadata['landing_origin']);
        $this->assertSame('selected_landing_paths_in_property', $result->metadata['scope']);
        $this->assertSame(100250000, $result->rows[0]->grossRevenueMicros);
        $this->assertSame(20000000, $result->rows[0]->refundMicros);
        $this->assertSame(80250000, $result->rows[0]->netRevenueMicros);
        $this->assertTrue($result->metadata['supplementary']);
        $this->assertFalse($result->metadata['authoritative_purchase_ledger']);
        $this->assertNull($result->metadata['new_customers']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), ':checkCompatibility') && array_column($request['metrics'], 'name') === self::METRICS && array_column($request['dimensions'], 'name') === self::DIMS);
    }

    #[Test]
    public function incompatible_dimensions_or_metrics_prevent_a_purchase_report(): void
    {
        $project = $this->connected();
        $compatibility = $this->compatible();
        $compatibility['metricCompatibilities'][1]['compatibility'] = 'INCOMPATIBLE';
        Http::fake(['*:checkCompatibility' => Http::response($compatibility)]);
        $result = app(GoogleAnalytics::class)->landingPurchases($project, [], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'));
        $this->assertSame(ReadStatus::Incompatible, $result->status);
        $this->assertStringContainsString('ecommercePurchases', $result->reason ?? '');
        Http::assertSentCount(1);
    }

    #[Test]
    public function thresholded_or_sampled_analytics_reports_are_incomplete(): void
    {
        $project = $this->connected();
        $body = $this->report([$this->row('20260801', '/service')]);
        $body['metadata']['subjectToThresholding'] = true;
        $body['metadata']['samplingMetadatas'] = [['samplesReadCount' => '100', 'samplingSpaceSize' => '200']];
        Http::fake(['*:checkCompatibility' => Http::response($this->compatible()), '*:runReport' => Http::response($body)]);
        $result = app(GoogleAnalytics::class)->landingPurchases($project, ['https://example.com/service'], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'));
        $this->assertSame(ReadStatus::Partial, $result->status);
        $this->assertTrue($result->metadata['subjectToThresholding']);
        $this->assertNotEmpty($result->metadata['quality_issues']);
    }

    #[Test]
    public function empty_analytics_response_can_omit_the_proto_default_zero_row_count(): void
    {
        $project = $this->connected();
        $body = $this->report([]);
        unset($body['rows'], $body['rowCount']);
        Http::fake(['*:checkCompatibility' => Http::response($this->compatible()), '*:runReport' => Http::response($body)]);
        $result = app(GoogleAnalytics::class)->landingPurchases($project, [], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'));
        $this->assertSame(ReadStatus::Complete, $result->status);
        $this->assertSame([], $result->rows);
    }

    #[Test]
    public function analytics_pagination_does_not_claim_complete_when_row_count_is_unread(): void
    {
        $project = $this->connected();
        config()->set('measurements.analytics_page_size', 1);
        config()->set('measurements.analytics_max_pages', 1);
        $body = $this->report([$this->row('20260801', '/service')]);
        $body['rowCount'] = 2;
        Http::fake(['*:checkCompatibility' => Http::response($this->compatible()), '*:runReport' => Http::response($body)]);
        $result = app(GoogleAnalytics::class)->landingPurchases($project, ['https://example.com/service'], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'));
        $this->assertSame(ReadStatus::Partial, $result->status);
        $this->assertFalse($result->metadata['pagination_complete']);
    }

    #[Test]
    public function a_currency_change_between_pages_is_partial_and_cannot_form_one_comparison(): void
    {
        $project = $this->connected();
        config()->set('measurements.analytics_page_size', 1);
        $first = $this->report([$this->row('20260801', '/service')]);
        $first['rowCount'] = 2;
        $second = $this->report([$this->row('20260802', '/service')]);
        $second['rowCount'] = 2;
        $second['metadata']['currencyCode'] = 'USD';
        Http::fake(['*:checkCompatibility' => Http::response($this->compatible()), '*:runReport' => Http::sequence()->push($first)->push($second)]);
        $result = app(GoogleAnalytics::class)->landingPurchases($project, ['https://example.com/service'], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'));
        $this->assertSame(ReadStatus::Partial, $result->status);
        $this->assertStringContainsString('changed its reporting currency', $result->reason ?? '');
        $this->assertCount(2, $result->rows);
    }

    private function connected(): Project
    {
        $project = Project::factory()->create(['website_url' => 'https://example.com']);
        app(CurrentProject::class)->run($project, fn () => ProjectIntegration::factory()->create());

        return $project;
    }

    /** @return array<string, mixed> */
    private function compatible(): array
    {
        return [
            'dimensionCompatibilities' => array_map(static fn (string $name): array => ['dimensionMetadata' => ['apiName' => $name], 'compatibility' => 'COMPATIBLE'], self::DIMS),
            'metricCompatibilities' => array_map(static fn (string $name): array => ['metricMetadata' => ['apiName' => $name], 'compatibility' => 'COMPATIBLE'], self::METRICS),
        ];
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function report(array $rows): array
    {
        return [
            'dimensionHeaders' => array_map(static fn (string $name): array => ['name' => $name], self::DIMS),
            'metricHeaders' => array_map(static fn (string $name): array => ['name' => $name, 'type' => 'TYPE_FLOAT'], self::METRICS),
            'rows' => $rows, 'rowCount' => count($rows), 'metadata' => ['currencyCode' => 'EUR', 'timeZone' => 'Europe/Lisbon'],
        ];
    }

    /** @return array<string, mixed> */
    private function row(string $day, string $landing): array
    {
        return [
            'dimensionValues' => array_map(static fn (string $value): array => ['value' => $value], [$day, $landing, 'Organic Search']),
            'metricValues' => array_map(static fn (string $value): array => ['value' => $value], ['20', '2', '100.25', '20', '80.25']),
        ];
    }
}
