<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Feedback\GoogleAnalytics;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GoogleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect' => 'http://localhost/integrations/google/callback',
        ]);
    }

    #[Test]
    public function a_connection_without_the_analytics_scope_is_not_configured(): void
    {
        $project = $this->connected(fn ($factory) => $factory->searchOnly());

        // Search Console alone is a perfectly good connection; it is just not
        // one this gateway can read from.
        $this->assertFalse($this->analytics()->isConfiguredFor($project));
    }

    #[Test]
    public function a_connection_without_a_chosen_property_is_not_configured(): void
    {
        $project = $this->connected(fn ($factory) => $factory->unchosen());

        $this->assertFalse($this->analytics()->isConfiguredFor($project));
    }

    #[Test]
    public function rows_are_matched_by_path_because_that_is_all_ga4_knows(): void
    {
        $project = $this->connected();

        Http::fake([
            'analyticsdata.googleapis.com/*' => Http::response([
                'rows' => [
                    $this->row('20260801', '/blog/post', 140, 96, 4320),
                    // A campaign link. The same article, and its numbers belong
                    // with the article's.
                    $this->row('20260801', '/blog/post?utm_source=newsletter', 20, 12, 500),
                    // Somebody else's page on the same property.
                    $this->row('20260801', '/pricing', 900, 700, 20000),
                ],
            ]),
        ]);

        $rows = $this->analytics()->engagement(
            $project,
            ['https://example.com/blog/post'],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-28'),
        );

        // Two rows for the same article, both matched — summing them is the
        // step's job, not the gateway's.
        $this->assertCount(2, $rows);
        $this->assertSame('https://example.com/blog/post', $rows[0]->url);
        $this->assertSame(140, $rows[0]->sessions);
        $this->assertSame(96, $rows[0]->engagedSessions);
        $this->assertSame(4320, $rows[0]->engagementSeconds);

        // GA4 answers YYYYMMDD with no separators; parsed as a date, not as a
        // number that happens to look like one.
        $this->assertSame('2026-08-01', $rows[0]->measuredOn->toDateString());

        Http::assertSent(function (ClientRequest $request): bool {
            return str_contains($request->url(), 'properties/123456789:runReport')
                && $request['dimensions'] === [['name' => 'date'], ['name' => 'pagePath']];
        });
    }

    #[Test]
    public function a_trailing_slash_is_not_a_different_page(): void
    {
        $project = $this->connected();

        Http::fake([
            'analyticsdata.googleapis.com/*' => Http::response([
                'rows' => [$this->row('20260801', '/blog/post/', 10, 6, 300)],
            ]),
        ]);

        $rows = $this->analytics()->engagement(
            $project,
            ['https://example.com/blog/post'],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-28'),
        );

        $this->assertCount(1, $rows);
    }

    #[Test]
    public function a_row_we_cannot_place_in_time_is_dropped_not_thrown_on(): void
    {
        $project = $this->connected();

        Http::fake([
            'analyticsdata.googleapis.com/*' => Http::response([
                'rows' => [
                    // GA4 folds high-cardinality dimensions into "(other)", and
                    // a malformed date must not take the whole fetch down with
                    // it — the other rows are still worth having.
                    $this->row('(other)', '/blog/post', 5, 3, 100),
                    $this->row('20260801', '/blog/post', 10, 6, 300),
                ],
            ]),
        ]);

        $rows = $this->analytics()->engagement(
            $project,
            ['https://example.com/blog/post'],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-28'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame(10, $rows[0]->sessions);
    }

    #[Test]
    public function paging_asks_for_a_stable_order(): void
    {
        $project = $this->connected();

        Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['rows' => []])]);

        $this->analytics()->engagement($project, [], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'));

        // Paging is by offset. Without a sort GA4 may order rows differently
        // per request, and page two would repeat and skip rows from page one.
        Http::assertSent(fn (ClientRequest $request): bool => $request['orderBys'] === [
            ['dimension' => ['dimensionName' => 'date']],
            ['dimension' => ['dimensionName' => 'pagePath']],
        ]);
    }

    #[Test]
    public function a_lost_grant_marks_the_connection_broken(): void
    {
        $project = $this->connected();

        Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'Invalid Credentials']], 401)]);

        $this->assertSame([], $this->analytics()->engagement(
            $project,
            ['https://example.com/a'],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-28'),
        ));

        $this->assertNotNull($this->integrationFor($project)?->failure_reason);
    }

    #[Test]
    public function losing_the_property_does_not_break_the_search_console_half(): void
    {
        $project = $this->connected();

        Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'denied']], 403)]);

        $this->assertSame([], $this->analytics()->engagement(
            $project,
            ['https://example.com/a'],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-28'),
        ));

        // One grant covers both APIs. Breaking it because GA4 said no would
        // stop search data too, which was working fine.
        $this->assertNull($this->integrationFor($project)?->failure_reason);
    }

    #[Test]
    public function the_channel_split_folds_both_social_groups_and_totals_everything(): void
    {
        $project = $this->connected();

        Http::fake([
            'analyticsdata.googleapis.com/*' => Http::response([
                'rows' => [
                    $this->channel('Direct', 220, 180, 9000, 4),
                    $this->channel('Referral', 130, 90, 4000, 2),
                    $this->channel('Organic Social', 150, 110, 5000, 3),
                    // A boosted post is still the account's audience arriving.
                    $this->channel('Paid Social', 50, 40, 1500, 1),
                    // Not named by §6, and still part of the denominator.
                    $this->channel('Organic Search', 450, 300, 20000, 7),
                ],
            ]),
        ]);

        $audience = $this->analytics()->audience($project, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'));

        $this->assertNotNull($audience);
        $this->assertSame(1000, $audience->totalSessions);
        $this->assertSame(220, $audience->directSessions);
        $this->assertSame(130, $audience->referralSessions);
        $this->assertSame(200, $audience->socialSessions);
        $this->assertSame(150, $audience->socialEngagedSessions);
        $this->assertSame(6500, $audience->socialEngagementSeconds);
        $this->assertSame(17, $audience->conversions);
    }

    #[Test]
    public function a_refused_channel_read_is_null_rather_than_an_audience_that_left(): void
    {
        $project = $this->connected();

        Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'nope']], 401)]);

        $this->assertNull(
            $this->analytics()->audience($project, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'))
        );

        // 401 is still 401: the grant is gone and the settings screen has to
        // say so, exactly as it does for the per-unit read.
        $this->assertNotNull($this->integrationFor($project)?->failure_reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $date, string $path, int $sessions, int $engaged, int $seconds): array
    {
        return [
            'dimensionValues' => [['value' => $date], ['value' => $path]],
            'metricValues' => [
                ['value' => (string) $sessions],
                ['value' => (string) $engaged],
                ['value' => (string) $seconds],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function channel(string $group, int $sessions, int $engaged, int $seconds, int $keyEvents): array
    {
        return [
            'dimensionValues' => [['value' => $group]],
            'metricValues' => [
                ['value' => (string) $sessions],
                ['value' => (string) $engaged],
                ['value' => (string) $seconds],
                ['value' => (string) $keyEvents],
            ],
        ];
    }

    private function analytics(): GoogleAnalytics
    {
        return app(GoogleAnalytics::class);
    }

    private function connected(?callable $state = null): Project
    {
        $project = Project::factory()->create(['website_url' => 'https://example.com']);

        app(CurrentProject::class)->run($project, function () use ($state): void {
            $factory = ProjectIntegration::factory();

            ($state === null ? $factory : $state($factory))->create();
        });

        return $project;
    }

    private function integrationFor(Project $project): ?ProjectIntegration
    {
        return app(CurrentProject::class)->run(
            $project,
            static fn (): ?ProjectIntegration => ProjectIntegration::query()->first(),
        );
    }
}
