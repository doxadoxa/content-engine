<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\IntegrationProvider;
use App\Integrations\Threads\ThreadsConnection;
use App\Integrations\Threads\ThreadsSearch;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Getting a Threads token, keeping it, and losing it (§9).
 *
 * Three things are being proved here, and they were three gaps rather than one.
 *
 * The first is that a token can be *obtained* at all. Everything downstream —
 * the publisher, the listener, the renewal — could use one, and the only way a
 * `project_integrations` row with `provider=threads` came into existence was
 * somebody writing the INSERT.
 *
 * The second is Meta's two-step exchange. A code buys a short-lived token
 * measured in an hour, and only a second trade turns it into the ~60-day one
 * worth storing. Stopping after the first leg would produce a connection that
 * works until dinner, so the round trip is asserted as three calls in order and
 * a failure at any leg is asserted to store nothing.
 *
 * The third is renewal, which had no test at all. It has both a ceiling and a
 * floor — the platform refuses an expired token and refuses one under a day old
 * — and until `threads:renew` existed it was only reached as a side effect of
 * publishing, which quietly made "posts at least monthly" a condition of
 * staying connected.
 *
 * Nothing reaches the network. `Http::preventStrayRequests()` is global.
 */
final class ThreadsConnectionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const SCOPES = [
        'threads_basic',
        'threads_content_publish',
        'threads_manage_insights',
        'threads_manage_replies',
        'threads_keyword_search',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.threads', [
            'client_id' => 'app-id',
            'client_secret' => 'app-secret',
            'redirect' => 'http://localhost/integrations/threads/callback',
            'webhook_secret' => null,
            'webhook_verify_token' => null,
            'scopes' => self::SCOPES,
        ]);
    }

    // -------------------------------------------------------------- the grant

    #[Test]
    public function connecting_sends_the_operator_to_meta_asking_for_both_contours(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        $response = $this->actingAs($operator)->get("/projects/{$project->getKey()}/threads/connect");

        $response->assertRedirectContains('threads.net/oauth/authorize');

        $query = [];
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('app-id', $query['client_id']);
        $this->assertSame('http://localhost/integrations/threads/callback', $query['redirect_uri']);

        // Comma-separated, which is Meta's spelling and not Google's. Spaces
        // here produce a consent screen for one scope named after all of them.
        $this->assertSame(implode(',', self::SCOPES), $query['scope']);
        $this->assertStringContainsString('threads_content_publish', (string) $query['scope']);
        $this->assertStringContainsString('threads_keyword_search', (string) $query['scope']);

        // The state is the whole of what proves the callback belongs to this
        // browser: Meta's authorisation endpoint takes no PKCE challenge.
        $this->assertSame(session('threads.oauth.state'), $query['state']);
        $this->assertSame($project->getKey(), session('threads.oauth.project'));
    }

    #[Test]
    public function the_callback_trades_the_code_twice_and_stores_what_lasts(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        $this->fakeExchange();

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/threads/callback?code=auth-code&state=the-state')
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        $integration = $this->integrationFor($project);

        $this->assertNotNull($integration);
        $this->assertSame(IntegrationProvider::Threads, $integration->provider);

        // The long-lived one, not the short-lived one it was traded for.
        $this->assertSame('long-lived-token', $integration->access_token);

        // Null on purpose: this provider has one credential, and copying it
        // into both columns would be two encrypted copies of one secret
        // drifting apart on every renewal.
        $this->assertNull($integration->refresh_token);

        $this->assertNotNull($integration->access_token_expires_at);
        $this->assertTrue($integration->access_token_expires_at->greaterThan(Carbon::now()->addDays(55)));
        $this->assertTrue($integration->access_token_expires_at->lessThan(Carbon::now()->addDays(60)));

        // Every publishing path addresses `/{user-id}/threads`, so the id is
        // learned once here rather than looked up per request.
        $this->assertSame('17841400000000000', app(ThreadsConnection::class)->userId($integration));
        $this->assertSame('brandname', $integration->config['username']);

        $this->assertSame(self::SCOPES, $integration->scopes);
        $this->assertTrue($integration->grants(ThreadsSearch::SCOPE));
        $this->assertArrayNotHasKey(ThreadsSearch::DEGRADED_FLAG, $integration->config);

        $this->assertSame($operator->getKey(), $integration->connected_by_id);
        $this->assertNotNull($integration->connected_at);
        $this->assertNull($integration->failure_reason);

        // A database dump is the likeliest way a token leaves the building.
        $stored = DB::table('project_integrations')->where('id', $integration->getKey())->first();
        $this->assertIsObject($stored);
        $this->assertNotSame('long-lived-token', $stored->access_token);
        $this->assertSame('long-lived-token', Crypt::decryptString((string) $stored->access_token));

        // The state is a one-time secret and must not survive the exchange.
        $this->assertNull(session('threads.oauth.state'));
        $this->assertNull(session('threads.oauth.project'));

        // Three calls, in order: code for short, short for long, long for who.
        Http::assertSentCount(3);

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'oauth/access_token')
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'auth-code');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'graph.threads.net/access_token')
            && str_contains($request->url(), 'grant_type=th_exchange_token')
            && str_contains($request->url(), 'access_token=short-lived-token'));

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/v1.0/me')
            && $request->hasHeader('Authorization', 'Bearer long-lived-token'));
    }

    #[Test]
    public function a_callback_with_the_wrong_state_connects_nothing(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        Http::fake();

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/threads/callback?code=auth-code&state=somebody-elses-state')
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        $this->assertNull($this->integrationFor($project));

        // Nothing was even offered to Meta: a forged callback must not make us
        // redeem a code somebody else chose.
        Http::assertNothingSent();
    }

    #[Test]
    public function a_callback_with_no_state_in_session_is_refused(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        Http::fake();

        $this->actingAs($operator)
            ->get('/integrations/threads/callback?code=auth-code&state=anything')
            ->assertRedirect('/projects');

        $this->assertNull($this->integrationFor($project));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_refusal_at_the_first_exchange_leaves_nothing_connected(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        Http::fake([
            'graph.threads.net/oauth/access_token' => Http::response([
                'error_type' => 'OAuthException',
                'code' => 400,
                'error_message' => 'This authorization code has expired.',
            ], 400),
        ]);

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/threads/callback?code=stale-code&state=the-state')
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        $this->assertNull($this->integrationFor($project));

        // It stopped at the first leg rather than carrying a token it never got
        // to the second.
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_short_lived_token_that_never_becomes_long_lived_is_not_stored(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        Http::fake([
            'graph.threads.net/oauth/access_token' => Http::response([
                'access_token' => 'short-lived-token',
                'user_id' => 17841400000000000,
            ]),
            'graph.threads.net/access_token*' => Http::response([
                'error' => ['message' => 'Please reduce the amount of data', 'code' => 1],
            ], 500),
        ]);

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/threads/callback?code=auth-code&state=the-state')
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        // Storing the short-lived one would give a connection that works for an
        // hour, which reads as a bug in this application rather than as a
        // consent screen worth revisiting.
        $this->assertNull($this->integrationFor($project));
    }

    #[Test]
    public function cancelling_at_meta_is_not_an_error(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        Http::fake();

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/threads/callback?error=access_denied&error_reason=user_denied&state=the-state')
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        $this->assertNull($this->integrationFor($project));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_grant_without_keyword_search_connects_and_records_the_degradation(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        // §11.2: `threads_keyword_search` is approved separately by Meta. A
        // project without it publishes and listens — it simply hears only its
        // own posts — so this is a connection, not a refusal.
        $this->fakeExchange(permissions: ['threads_basic', 'threads_content_publish']);

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/threads/callback?code=auth-code&state=the-state');

        $integration = $this->integrationFor($project);

        $this->assertNotNull($integration);
        $this->assertSame(['threads_basic', 'threads_content_publish'], $integration->scopes);
        $this->assertFalse($integration->grants(ThreadsSearch::SCOPE));

        // Written in the shape ThreadsSearch reads, so the listener believes it
        // rather than spending a refused request per keyword rediscovering it.
        $flag = $integration->config[ThreadsSearch::DEGRADED_FLAG];
        $this->assertIsArray($flag);
        $this->assertFalse($flag['granted']);
        $this->assertIsString($flag['observed_at']);

        // Not `failure_reason`: that means "this connection cannot answer", and
        // writing a missing optional scope there would stop publishing too.
        $this->assertNull($integration->failure_reason);
    }

    #[Test]
    public function reconnecting_after_the_approval_clears_the_degradation(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        // A project connected before the approval landed, and a listener that
        // has since recorded the degradation on the row.
        $this->connect($project, [
            'scopes' => ['threads_basic'],
            'config' => [
                'user_id' => '17841400000000000',
                'username' => 'brandname',
                ThreadsSearch::DEGRADED_FLAG => [
                    'granted' => false,
                    'observed_at' => Carbon::now()->subDay()->toIso8601String(),
                    'reason' => 'Threads refused the search endpoint.',
                ],
            ],
        ]);

        $this->fakeExchange();

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/threads/callback?code=second-code&state=the-state');

        $integration = $this->integrationFor($project);

        // One row, and the stale flag gone: §11.2's approval arrives once, and
        // a config that still said "not granted" would keep listening degraded
        // for a week after the grant that fixed it.
        $this->assertNotNull($integration);
        $this->assertSame(1, DB::table('project_integrations')->count());
        $this->assertArrayNotHasKey(ThreadsSearch::DEGRADED_FLAG, $integration->config);
        $this->assertTrue($integration->grants(ThreadsSearch::SCOPE));
    }

    #[Test]
    public function connecting_without_a_meta_app_says_so_instead_of_failing(): void
    {
        config()->set('services.threads.client_id', null);
        config()->set('services.threads.client_secret', null);

        [$operator, $project] = $this->operatorWithProject();

        $this->actingAs($operator)
            ->get("/projects/{$project->getKey()}/threads/connect")
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        $this->assertNull($this->integrationFor($project));
    }

    #[Test]
    public function another_operators_project_cannot_be_connected_or_disconnected(): void
    {
        [$operator] = $this->operatorWithProject();
        $theirs = Project::factory()->create();

        $this->actingAs($operator)
            ->get("/projects/{$theirs->getKey()}/threads/connect")
            ->assertNotFound();

        $this->actingAs($operator)
            ->delete("/projects/{$theirs->getKey()}/threads")
            ->assertNotFound();
    }

    #[Test]
    public function disconnecting_deletes_our_copy_and_asks_meta_nothing(): void
    {
        [$operator, $project] = $this->operatorWithProject();
        $this->connect($project);

        Http::fake();

        $this->actingAs($operator)
            ->delete("/projects/{$project->getKey()}/threads")
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        $this->assertNull($this->integrationFor($project));

        // Unlike Google's disconnect, there is nothing to revoke: the Threads
        // API publishes no revoke endpoint, and withdrawing an app's access is
        // the account holder's to do in their own settings.
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- keeping it

    #[Test]
    public function a_token_inside_its_renewal_window_is_traded_for_a_fresh_sixty_days(): void
    {
        [, $project] = $this->operatorWithProject();
        $integration = $this->connect($project, [
            'access_token_expires_at' => Carbon::now()->addDays(3),
            'connected_at' => Carbon::now()->subDays(53),
        ]);

        $this->fakeRenewal();

        $this->assertSame('renewed-token', app(ThreadsConnection::class)->accessToken($integration));

        $stored = $this->integrationFor($project);

        $this->assertSame('renewed-token', $stored?->access_token);
        $this->assertTrue($stored->access_token_expires_at?->greaterThan(Carbon::now()->addDays(55)));

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'refresh_access_token')
            && str_contains($request->url(), 'grant_type=th_refresh_token'));
    }

    #[Test]
    public function a_token_with_weeks_left_is_not_offered_for_renewal(): void
    {
        [, $project] = $this->operatorWithProject();
        $integration = $this->connect($project, [
            'access_token_expires_at' => Carbon::now()->addDays(40),
            'connected_at' => Carbon::now()->subDays(20),
        ]);

        Http::fake();

        // Renewing early is not free: it spends a request and resets nothing
        // that needed resetting.
        $this->assertSame('threads-long-lived-token', app(ThreadsConnection::class)->accessToken($integration));

        Http::assertNothingSent();
    }

    #[Test]
    public function a_token_under_a_day_old_is_left_alone_because_the_platform_would_refuse_it(): void
    {
        [, $project] = $this->operatorWithProject();

        // Inside the window by expiry and outside it by age — the floor Meta
        // imposes, and the reason `needsRenewal()` has two bounds rather than
        // one.
        $integration = $this->connect($project, [
            'access_token_expires_at' => Carbon::now()->addDays(3),
            'connected_at' => Carbon::now()->subHours(2),
        ]);

        Http::fake();

        $this->assertSame('threads-long-lived-token', app(ThreadsConnection::class)->accessToken($integration));

        Http::assertNothingSent();
    }

    #[Test]
    public function an_expired_token_marks_the_connection_broken_rather_than_trying_to_renew_it(): void
    {
        [, $project] = $this->operatorWithProject();
        $integration = $this->connect($project, [
            'access_token_expires_at' => Carbon::now()->subMinute(),
            'connected_at' => Carbon::now()->subDays(61),
        ]);

        Http::fake();

        $this->assertNull(app(ThreadsConnection::class)->accessToken($integration));

        $stored = $this->integrationFor($project);

        // The platform refuses to renew an expired token, so there is nothing
        // to try and only a human reconnecting fixes it. Recorded on the row so
        // the settings screen can say so.
        $this->assertNotNull($stored?->failure_reason);
        $this->assertFalse(app(ThreadsConnection::class)->isUsable($stored));

        Http::assertNothingSent();
    }

    #[Test]
    public function a_renewal_answering_without_a_token_leaves_the_working_one_in_place(): void
    {
        [, $project] = $this->operatorWithProject();
        $integration = $this->connect($project, [
            'access_token_expires_at' => Carbon::now()->addDays(3),
            'connected_at' => Carbon::now()->subDays(53),
        ]);

        Http::fake([
            'graph.threads.net/v1.0/refresh_access_token*' => Http::response(['expires_in' => 5_183_944]),
        ]);

        // The current token is still valid for days by construction, so an
        // answer that makes no sense is not a reason to break the connection or
        // to fail whatever asked.
        $this->assertSame('threads-long-lived-token', app(ThreadsConnection::class)->accessToken($integration));

        $stored = $this->integrationFor($project);

        $this->assertSame('threads-long-lived-token', $stored?->access_token);
        $this->assertNull($stored->failure_reason);
    }

    #[Test]
    public function a_renewal_meta_refuses_outright_marks_the_connection_broken(): void
    {
        [, $project] = $this->operatorWithProject();
        $integration = $this->connect($project, [
            'access_token_expires_at' => Carbon::now()->addDays(3),
            'connected_at' => Carbon::now()->subDays(53),
        ]);

        Http::fake([
            'graph.threads.net/v1.0/refresh_access_token*' => Http::response([
                'error' => ['message' => 'Error validating access token: the session has been invalidated.', 'code' => 190],
            ], 401),
        ]);

        $this->assertNull(app(ThreadsConnection::class)->accessToken($integration));

        $stored = $this->integrationFor($project);

        $this->assertNotNull($stored?->failure_reason);
        $this->assertFalse(app(ThreadsConnection::class)->isUsable($stored));
    }

    // ------------------------------------------------------------- the sweeper

    #[Test]
    public function threads_renew_walks_every_project_that_has_a_connection(): void
    {
        [, $first] = $this->operatorWithProject();
        $second = Project::factory()->create(['name' => 'Second']);
        $withoutOne = Project::factory()->create(['name' => 'No Threads']);

        $due = [
            'access_token_expires_at' => Carbon::now()->addDays(3),
            'connected_at' => Carbon::now()->subDays(53),
        ];

        $this->connect($first, $due);
        $this->connect($second, $due);

        $this->fakeRenewal();

        $this->renew()->assertSuccessful()->run();

        // Both, and only both. A project with no connection is not visited at
        // all, which is what keeps a nightly sweep proportional to the number
        // of connections rather than to the number of projects.
        $this->assertSame('renewed-token', $this->integrationFor($first)?->access_token);
        $this->assertSame('renewed-token', $this->integrationFor($second)?->access_token);
        $this->assertNull($this->integrationFor($withoutOne));

        Http::assertSentCount(2);
    }

    #[Test]
    public function threads_renew_leaves_a_token_outside_its_window_alone(): void
    {
        [, $project] = $this->operatorWithProject();
        $this->connect($project, [
            'access_token_expires_at' => Carbon::now()->addDays(40),
            'connected_at' => Carbon::now()->subDays(20),
        ]);

        Http::fake();

        // The command is a walk, not a policy: when to renew stays the one
        // answer `accessToken()` already gives.
        $this->renew()->assertSuccessful()->run();

        Http::assertNothingSent();
    }

    #[Test]
    public function threads_renew_says_so_when_a_named_project_has_no_connection(): void
    {
        [, $project] = $this->operatorWithProject();

        Http::fake();

        $this->renew(['project' => $project->slug])
            ->expectsOutputToContain('no Threads connection')
            ->assertSuccessful()
            ->run();

        Http::assertNothingSent();
    }

    #[Test]
    public function threads_renew_reports_a_connection_that_has_gone_broken(): void
    {
        [, $project] = $this->operatorWithProject();
        $this->connect($project, [
            'failure_reason' => 'The Threads token expired before it was renewed.',
            'access_token' => null,
            'access_token_expires_at' => null,
        ]);

        Http::fake();

        // A dead token has to be visible before somebody notices the posts
        // stopped, which is the whole reason the sweep is scheduled.
        $this->renew()
            ->expectsOutputToContain('reconnect')
            ->assertSuccessful()
            ->run();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------ the settings panel

    #[Test]
    public function the_settings_screen_names_the_connected_account_and_when_its_access_runs_out(): void
    {
        [$operator, $project] = $this->operatorWithProject();
        $this->connect($project);

        $this->actingAs($operator)
            ->get("/projects/{$project->getKey()}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('threads.state', 'connected')
                ->where('threads.username', 'brandname')
                ->where('threads.grants_keyword_search', true)
                ->whereNot('threads.expires_at', null)
            );
    }

    #[Test]
    public function the_settings_screen_asks_for_a_reconnection_when_the_token_died(): void
    {
        [$operator, $project] = $this->operatorWithProject();
        $this->connect($project, [
            'failure_reason' => 'The Threads token expired before it was renewed.',
            'access_token' => null,
            'access_token_expires_at' => null,
        ]);

        $this->actingAs($operator)
            ->get("/projects/{$project->getKey()}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('threads.state', 'broken')
                ->where('threads.reason', 'The Threads token expired before it was renewed.')
            );
    }

    #[Test]
    public function the_settings_screen_offers_a_connection_when_there_is_none(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        $this->actingAs($operator)
            ->get("/projects/{$project->getKey()}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('threads.state', 'disconnected'));
    }

    #[Test]
    public function the_settings_screen_says_the_installation_has_no_threads_app_rather_than_offering_a_button(): void
    {
        config()->set('services.threads.client_id', null);
        config()->set('services.threads.client_secret', null);

        [$operator, $project] = $this->operatorWithProject();

        // The one state where a Connect button would be a lie: there is no app
        // to send anybody to, and only somebody with the environment file can
        // change that.
        $this->actingAs($operator)
            ->get("/projects/{$project->getKey()}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('threads.state', 'unavailable')
                ->where('threads.reason', 'This installation has no Threads app configured.')
            );
    }

    #[Test]
    public function the_settings_screen_reports_listening_that_was_degraded_after_connecting(): void
    {
        [$operator, $project] = $this->operatorWithProject();
        $this->connect($project, [
            'config' => [
                'user_id' => '17841400000000000',
                'username' => 'brandname',
                ThreadsSearch::DEGRADED_FLAG => [
                    'granted' => false,
                    'observed_at' => Carbon::now()->toIso8601String(),
                    'reason' => 'Threads refused the search endpoint.',
                ],
            ],
        ]);

        // Discovered by the listener rather than at the consent screen, which
        // is the only way an installation whose token endpoint says nothing
        // about permissions ever finds out. The panel has to read both.
        $this->actingAs($operator)
            ->get("/projects/{$project->getKey()}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('threads.state', 'connected')
                ->where('threads.grants_keyword_search', false)
            );
    }

    // ------------------------------------------------------------------ setup

    /**
     * `artisan()` is declared as returning `PendingCommand|int`, so without
     * this every call site would repeat the same annotation to chain
     * assertions onto it.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function renew(array $arguments = []): PendingCommand
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('threads:renew', $arguments);

        return $command;
    }

    /**
     * @return array{User, Project}
     */
    private function operatorWithProject(): array
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create();
        $operator->projects()->attach($project, ['role' => 'owner']);

        return [$operator, $project];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connect(Project $project, array $attributes = []): ProjectIntegration
    {
        return app(CurrentProject::class)->run($project, static fn (): ProjectIntegration => ProjectIntegration::factory()
            ->threads()
            ->create([
                'config' => ['user_id' => '17841400000000000', 'username' => 'brandname'],
                ...$attributes,
            ]));
    }

    private function integrationFor(Project $project): ?ProjectIntegration
    {
        return app(CurrentProject::class)->run(
            $project,
            static fn (): ?ProjectIntegration => ProjectIntegration::query()->first(),
        );
    }

    /**
     * Meta's three answers: a short-lived token, a long-lived one, and who it
     * belongs to.
     *
     * @param  list<string>|null  $permissions
     */
    private function fakeExchange(?array $permissions = null): void
    {
        Http::fake([
            'graph.threads.net/oauth/access_token' => Http::response([
                'access_token' => 'short-lived-token',
                'user_id' => 17841400000000000,
                'permissions' => $permissions ?? self::SCOPES,
            ]),
            'graph.threads.net/access_token*' => Http::response([
                'access_token' => 'long-lived-token',
                'token_type' => 'bearer',
                'expires_in' => 5_183_944,
            ]),
            'graph.threads.net/v1.0/me*' => Http::response([
                'id' => '17841400000000000',
                'username' => 'brandname',
            ]),
        ]);
    }

    private function fakeRenewal(): void
    {
        Http::fake([
            'graph.threads.net/v1.0/refresh_access_token*' => Http::response([
                'access_token' => 'renewed-token',
                'token_type' => 'bearer',
                'expires_in' => 5_183_944,
            ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function pendingSession(Project $project): array
    {
        return [
            'threads.oauth.state' => 'the-state',
            'threads.oauth.project' => $project->getKey(),
        ];
    }
}
