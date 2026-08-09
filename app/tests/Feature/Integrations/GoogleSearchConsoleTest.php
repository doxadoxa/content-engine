<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Feedback\GoogleSearchConsole;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GoogleSearchConsoleTest extends TestCase
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
    public function a_project_without_a_connection_is_not_configured(): void
    {
        $project = Project::factory()->create();

        $this->assertFalse($this->console()->isConfiguredFor($project));
    }

    #[Test]
    public function a_connection_without_a_chosen_property_is_not_configured(): void
    {
        $project = $this->connected(fn ($factory) => $factory->unchosen());

        // Connected but pointed at nothing. Treating this as configured would
        // make the step fetch from a property that does not exist.
        $this->assertFalse($this->console()->isConfiguredFor($project));
    }

    #[Test]
    public function a_connection_without_the_search_console_scope_is_not_configured(): void
    {
        $project = $this->connected(fn ($factory) => $factory->state([
            'scopes' => [ProjectIntegration::SCOPE_ANALYTICS],
        ]));

        $this->assertFalse($this->console()->isConfiguredFor($project));
    }

    #[Test]
    public function a_broken_connection_is_not_configured(): void
    {
        $project = $this->connected(fn ($factory) => $factory->broken());

        $this->assertFalse($this->console()->isConfiguredFor($project));
    }

    #[Test]
    public function rows_are_matched_to_our_urls_and_everything_else_is_dropped(): void
    {
        $project = $this->connected();

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites/*' => Http::response([
                'rows' => [
                    ['keys' => ['2026-08-01', 'https://example.com/a'], 'impressions' => 120, 'clicks' => 7, 'position' => 8.4],
                    // The site's home page. Real traffic, not ours to measure.
                    ['keys' => ['2026-08-01', 'https://example.com/'], 'impressions' => 9000, 'clicks' => 400, 'position' => 1.2],
                    // A trailing slash Google added and we never stored.
                    ['keys' => ['2026-08-02', 'https://example.com/b/'], 'impressions' => 40, 'clicks' => 1, 'position' => 14.0],
                ],
            ]),
        ]);

        $rows = $this->console()->performance(
            $project,
            ['https://example.com/a', 'https://example.com/b'],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-28'),
        );

        $this->assertCount(2, $rows);
        $this->assertSame(120, $rows[0]->impressions);
        $this->assertSame(8.4, $rows[0]->position);

        // Reported against the URL we hold, not the one Google spelled: the
        // step matches these back by exact string, and a trailing slash there
        // would silently drop the row.
        $this->assertSame('https://example.com/b', $rows[1]->url);

        Http::assertSent(function (ClientRequest $request): bool {
            // The property name goes in the path and `sc-domain:` contains a
            // colon, so it has to be encoded rather than concatenated.
            return str_contains($request->url(), 'sc-domain%3Aexample.com')
                && $request['dimensions'] === ['date', 'page']
                && $request['startDate'] === '2026-08-01';
        });
    }

    #[Test]
    public function a_successful_read_records_when_it_happened(): void
    {
        $project = $this->connected();

        Http::fake(['www.googleapis.com/*' => Http::response(['rows' => []])]);

        $this->console()->performance($project, [], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'));

        // The settings screen says "last read"; without this it says nothing
        // and a connection that stopped working looks the same as one that
        // never had any data.
        $this->assertNotNull($this->integrationFor($project)?->last_synced_at);
    }

    #[Test]
    public function a_lost_grant_during_a_fetch_marks_the_connection_broken(): void
    {
        $project = $this->connected();

        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'Invalid Credentials']], 401)]);

        $rows = $this->console()->performance($project, ['https://example.com/a'], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'));

        $this->assertSame([], $rows);
        $this->assertNotNull($this->integrationFor($project)?->failure_reason);
    }

    #[Test]
    public function losing_access_to_the_property_does_not_break_the_whole_connection(): void
    {
        $project = $this->connected();

        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'User does not have permission']], 403)]);

        $this->assertSame([], $this->console()->performance($project, [], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28')));

        // The operator fixes this in Search Console by regranting access to the
        // property, not by reconnecting here — so the grant stays.
        $this->assertNull($this->integrationFor($project)?->failure_reason);
    }

    #[Test]
    public function a_refused_fetch_does_not_claim_to_have_read_anything(): void
    {
        $project = $this->connected();

        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'nope']], 403)]);

        $this->console()->performance($project, ['https://example.com/a'], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28'));

        // "Last read: just now" next to no data is the settings screen telling
        // the operator everything is fine while nothing is being collected.
        $this->assertNull($this->integrationFor($project)?->last_synced_at);
    }

    #[Test]
    public function two_paths_differing_only_in_case_are_not_folded_together(): void
    {
        $project = $this->connected();

        Http::fake([
            'www.googleapis.com/*' => Http::response([
                'rows' => [
                    ['keys' => ['2026-08-01', 'HTTPS://Example.com/Blog'], 'impressions' => 10, 'clicks' => 1],
                ],
            ]),
        ]);

        // Host casing is not a difference; path casing is — /Blog and /blog can
        // be two different pages, and folding them attributes one page's
        // numbers to the other.
        $rows = $this->console()->performance(
            $project,
            ['https://example.com/blog'],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-28'),
        );

        $this->assertSame([], $rows);

        $matched = $this->console()->performance(
            $project,
            ['https://example.com/Blog'],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-28'),
        );

        $this->assertCount(1, $matched);
    }

    #[Test]
    public function a_full_page_of_rows_is_followed_by_another_request(): void
    {
        $project = $this->connected();

        // 25,000 is Google's ceiling per request, so a full page means there is
        // more. Stopping there would silently lose a large site's data.
        $full = array_map(
            static fn (int $i): array => [
                'keys' => ['2026-08-01', "https://example.com/{$i}"],
                'impressions' => 1,
                'clicks' => 0,
            ],
            range(1, 25000),
        );

        Http::fakeSequence()
            ->push(['rows' => $full])
            ->push(['rows' => [
                ['keys' => ['2026-08-02', 'https://example.com/last'], 'impressions' => 5, 'clicks' => 1],
            ]]);

        $rows = $this->console()->performance(
            $project,
            ['https://example.com/last'],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-28'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame('https://example.com/last', $rows[0]->url);
        Http::assertSentCount(2);
    }

    #[Test]
    public function brand_demand_is_matched_on_the_name_and_asked_by_query(): void
    {
        $project = $this->connected();

        Http::fake([
            'www.googleapis.com/*' => Http::response([
                'rows' => [
                    ['keys' => ['example brand'], 'impressions' => 100, 'clicks' => 30],
                    ['keys' => ['examplebrand lisbon'], 'impressions' => 40, 'clicks' => 9],
                    ['keys' => ['how to clean a kettle'], 'impressions' => 5000, 'clicks' => 200],
                ],
            ]),
        ]);

        $project->forceFill(['name' => 'Example Brand'])->save();

        $demand = $this->console()->brandDemand($project, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'));

        $this->assertNotNull($demand);
        $this->assertSame(140, $demand->impressions);
        $this->assertSame(39, $demand->clicks);
        $this->assertSame(['example brand' => 100, 'examplebrand lisbon' => 40], $demand->queries);

        // The slice is by query, not by page: nobody types a URL, and the
        // query dimension is the only one that can say who was looking for us.
        Http::assertSent(static fn (ClientRequest $request): bool => $request['dimensions'] === ['query']);
    }

    #[Test]
    public function a_refused_brand_read_is_null_rather_than_a_brand_nobody_searched_for(): void
    {
        $project = $this->connected();

        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'nope']], 403)]);

        // Zero brand demand is a real and terrible reading. A refusal must not
        // be able to produce it.
        $this->assertNull(
            $this->console()->brandDemand($project, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'))
        );

        $this->assertNull($this->integrationFor($project)?->last_synced_at);
    }

    private function console(): GoogleSearchConsole
    {
        return app(GoogleSearchConsole::class);
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
