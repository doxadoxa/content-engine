<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Google\GoogleConnection;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GoogleConnectionTest extends TestCase
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
    public function connecting_sends_the_operator_to_google_asking_for_lasting_read_access(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        $response = $this->actingAs($operator)->get("/projects/{$project->getKey()}/google/connect");

        $response->assertRedirectContains('accounts.google.com');

        $query = [];
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);

        $scope = $query['scope'];
        $this->assertIsString($scope);

        // Both scopes in one consent screen: it is one question to the person
        // answering it.
        $this->assertStringContainsString('webmasters.readonly', $scope);
        $this->assertStringContainsString('analytics.readonly', $scope);
        // Offline + consent, or Google returns no refresh token on a reconnect
        // and the connection quietly dies after an hour.
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('S256', $query['code_challenge_method']);

        $verifier = session('google.oauth.verifier');
        $this->assertIsString($verifier);

        // The challenge must actually be the verifier's hash, or PKCE is a
        // parameter that looks right and proves nothing.
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $query['code_challenge'],
        );

        $this->assertSame(session('google.oauth.state'), $query['state']);
        $this->assertSame($project->getKey(), session('google.oauth.project'));
    }

    #[Test]
    public function the_callback_stores_the_grant_and_the_tokens_are_ciphertext_at_rest(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-abc',
                'refresh_token' => 'refresh-xyz',
                'expires_in' => 3600,
                'scope' => ProjectIntegration::SCOPE_SEARCH_CONSOLE.' '.ProjectIntegration::SCOPE_ANALYTICS,
            ]),
        ]);

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/google/callback?code=auth-code&state=the-state')
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        $integration = $this->integrationFor($project);

        $this->assertNotNull($integration);
        $this->assertSame('refresh-xyz', $integration->refresh_token);
        $this->assertSame($operator->getKey(), $integration->connected_by_id);
        $this->assertTrue($integration->grants(ProjectIntegration::SCOPE_ANALYTICS));

        // A database dump is the likeliest way a refresh token leaves the
        // building, so the column must not be readable.
        $stored = DB::table('project_integrations')->where('id', $integration->getKey())->first();
        $this->assertIsObject($stored);
        $this->assertNotSame('refresh-xyz', $stored->refresh_token);
        $this->assertSame('refresh-xyz', Crypt::decryptString((string) $stored->refresh_token));

        // The verifier is a one-time secret and must not survive the exchange.
        $this->assertNull(session('google.oauth.verifier'));
        $this->assertNull(session('google.oauth.state'));

        Http::assertSent(fn (ClientRequest $request): bool => $request['code_verifier'] === 'the-verifier'
            && $request['grant_type'] === 'authorization_code');
    }

    #[Test]
    public function a_callback_with_the_wrong_state_connects_nothing(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        Http::fake();

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/google/callback?code=auth-code&state=somebody-elses-state')
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        $this->assertNull($this->integrationFor($project));

        // Nothing was even offered to Google: a forged callback must not cause
        // us to redeem a code an attacker chose.
        Http::assertNothingSent();
    }

    #[Test]
    public function a_callback_with_no_state_in_session_is_refused(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        Http::fake();

        // No pending session at all — a bare hit on the callback URL.
        $this->actingAs($operator)
            ->get('/integrations/google/callback?code=auth-code&state=anything')
            ->assertRedirect('/projects');

        $this->assertNull($this->integrationFor($project));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_grant_without_a_refresh_token_is_refused_rather_than_stored(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-abc',
                'expires_in' => 3600,
                'scope' => ProjectIntegration::SCOPE_SEARCH_CONSOLE,
            ]),
        ]);

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/google/callback?code=auth-code&state=the-state')
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        // Storing it would give a connection that works for an hour and then
        // stops, which reads as a bug in this application rather than as a
        // consent screen that needs revisiting.
        $this->assertNull($this->integrationFor($project));
    }

    #[Test]
    public function cancelling_at_google_is_not_an_error(): void
    {
        [$operator, $project] = $this->operatorWithProject();

        Http::fake();

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/google/callback?error=access_denied&state=the-state')
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        $this->assertNull($this->integrationFor($project));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_short_lived_token_is_still_given_a_usable_life(): void
    {
        [, $project] = $this->operatorWithProject();
        $integration = $this->connect($project, expired: true);

        // Google is entitled to answer with a short life. Subtracting the
        // safety margin from it must not produce a token that is already
        // expired, or every call refreshes and the token endpoint rate-limits
        // us out of the feature.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'brief-token',
                'expires_in' => 60,
                'scope' => ProjectIntegration::SCOPE_SEARCH_CONSOLE,
            ]),
        ]);

        app(GoogleConnection::class)->accessToken($integration);

        $stored = $this->integrationFor($project);

        $this->assertNotNull($stored?->access_token_expires_at);
        $this->assertTrue(
            $stored->access_token_expires_at->isFuture(),
            'A freshly refreshed token must not arrive already expired.',
        );

        // And the next call reuses it rather than going out again.
        app(GoogleConnection::class)->accessToken($stored);

        Http::assertSentCount(1);
    }

    #[Test]
    public function reconnecting_replaces_the_grant_rather_than_keeping_two(): void
    {
        [$operator, $project] = $this->operatorWithProject();
        $this->connect($project);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-second',
                'refresh_token' => 'refresh-second',
                'expires_in' => 3600,
                'scope' => ProjectIntegration::SCOPE_SEARCH_CONSOLE,
            ]),
        ]);

        $this->actingAs($operator)
            ->withSession($this->pendingSession($project))
            ->get('/integrations/google/callback?code=auth-code&state=the-state');

        $this->assertSame(1, DB::table('project_integrations')->count());

        $integration = $this->integrationFor($project);

        $this->assertSame('refresh-second', $integration?->refresh_token);
        // Reconnecting is what an operator does when it broke, so the failure
        // has to clear or the screen keeps telling them to do it again.
        $this->assertNull($integration->failure_reason);
        // Scopes come from the new grant: unticking Analytics on the second
        // consent screen must not leave the first grant's scopes behind.
        $this->assertFalse($integration->grants(ProjectIntegration::SCOPE_ANALYTICS));
    }

    #[Test]
    public function connecting_without_google_set_up_says_so_instead_of_failing(): void
    {
        config()->set('services.google.client_id', null);
        config()->set('services.google.client_secret', null);

        [$operator, $project] = $this->operatorWithProject();

        // An installation with no OAuth client is a configuration gap, and the
        // operator can only act on it if the screen says which one.
        $this->actingAs($operator)
            ->get("/projects/{$project->getKey()}/google/connect")
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        $this->assertNull($this->integrationFor($project));
    }

    #[Test]
    public function another_operators_project_cannot_be_connected_or_disconnected(): void
    {
        [$operator] = $this->operatorWithProject();
        $theirs = Project::factory()->create();

        $this->actingAs($operator)
            ->get("/projects/{$theirs->getKey()}/google/connect")
            ->assertNotFound();

        $this->actingAs($operator)
            ->delete("/projects/{$theirs->getKey()}/google")
            ->assertNotFound();
    }

    #[Test]
    public function choosing_properties_saves_what_the_account_can_actually_see(): void
    {
        [$operator, $project] = $this->operatorWithProject();
        $this->connect($project);

        $this->fakeListings();

        $this->actingAs($operator)->patch("/projects/{$project->getKey()}/google", [
            'search_console_site' => 'https://example.com/',
            'analytics_property' => 'properties/111',
        ])->assertRedirect("/projects/{$project->getKey()}/edit");

        $integration = $this->integrationFor($project);

        $this->assertSame('https://example.com/', $integration?->searchConsoleSite());
        $this->assertSame('properties/111', $integration->analyticsProperty());
    }

    #[Test]
    public function a_property_this_account_cannot_see_is_not_stored(): void
    {
        [$operator, $project] = $this->operatorWithProject();
        $this->connect($project);

        $this->fakeListings();

        // The form is a list of values that came from Google, but the request
        // is whatever the client sends. Trusting it would let one project be
        // pointed at a property belonging to somebody else's account.
        $this->actingAs($operator)->patch("/projects/{$project->getKey()}/google", [
            'search_console_site' => 'sc-domain:someone-elses.com',
            'analytics_property' => 'properties/999',
        ]);

        $integration = $this->integrationFor($project);

        $this->assertNull($integration?->searchConsoleSite());
        $this->assertNull($integration->analyticsProperty());
    }

    #[Test]
    public function choosing_properties_on_a_project_you_are_not_in_is_refused(): void
    {
        [$operator] = $this->operatorWithProject();
        $theirs = Project::factory()->create();

        $this->actingAs($operator)
            ->patch("/projects/{$theirs->getKey()}/google", [
                'search_console_site' => 'sc-domain:example.com',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function disconnecting_revokes_at_google_and_deletes_our_copy(): void
    {
        [$operator, $project] = $this->operatorWithProject();
        $this->connect($project);

        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('')]);

        $this->actingAs($operator)
            ->delete("/projects/{$project->getKey()}/google")
            ->assertRedirect("/projects/{$project->getKey()}/edit");

        $this->assertNull($this->integrationFor($project));

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'revoke')
            && $request['token'] === 'refresh-token');
    }

    #[Test]
    public function disconnecting_still_deletes_when_google_will_not_answer(): void
    {
        [$operator, $project] = $this->operatorWithProject();
        $this->connect($project);

        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 500)]);

        $this->actingAs($operator)->delete("/projects/{$project->getKey()}/google");

        // The operator asked to disconnect. Leaving a live token here because
        // somebody else's endpoint was down is the wrong way to fail.
        $this->assertNull($this->integrationFor($project));
    }

    #[Test]
    public function an_expired_access_token_is_refreshed_and_the_new_one_kept(): void
    {
        [, $project] = $this->operatorWithProject();
        $integration = $this->connect($project, expired: true);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-token',
                'expires_in' => 3600,
                'scope' => ProjectIntegration::SCOPE_SEARCH_CONSOLE,
            ]),
        ]);

        $token = app(GoogleConnection::class)->accessToken($integration);

        $this->assertSame('fresh-token', $token);

        // Persisted, or the next request refreshes again — a token endpoint
        // called once per API call is both slow and rate-limited.
        $this->assertSame('fresh-token', $this->integrationFor($project)?->access_token);

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_live_access_token_is_reused(): void
    {
        [, $project] = $this->operatorWithProject();
        $integration = $this->connect($project);

        Http::fake();

        $this->assertSame('access-token', app(GoogleConnection::class)->accessToken($integration));

        Http::assertNothingSent();
    }

    #[Test]
    public function a_revoked_grant_marks_the_connection_broken_instead_of_retrying_forever(): void
    {
        [, $project] = $this->operatorWithProject();
        $integration = $this->connect($project, expired: true);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);

        $this->assertNull(app(GoogleConnection::class)->accessToken($integration));

        $stored = $this->integrationFor($project);

        // Recorded on the row so the settings screen can say "reconnect".
        // Retrying an invalid_grant on a schedule is how a project stops
        // collecting data for a month without anybody noticing.
        $this->assertNotNull($stored?->failure_reason);
        $this->assertFalse($stored->isUsable());
        $this->assertNull(app(GoogleConnection::class)->accessToken($stored));

        // The second call did not go out again.
        Http::assertSentCount(1);
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

    private function connect(Project $project, bool $expired = false): ProjectIntegration
    {
        return app(CurrentProject::class)->run($project, function () use ($expired): ProjectIntegration {
            $factory = ProjectIntegration::factory();

            return ($expired ? $factory->expired() : $factory)->create();
        });
    }

    private function integrationFor(Project $project): ?ProjectIntegration
    {
        return app(CurrentProject::class)->run(
            $project,
            static fn (): ?ProjectIntegration => ProjectIntegration::query()->first(),
        );
    }

    private function fakeListings(): void
    {
        Http::fake([
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ]),
            'analyticsadmin.googleapis.com/*' => Http::response([
                'accountSummaries' => [
                    [
                        'displayName' => 'Account',
                        'propertySummaries' => [
                            ['property' => 'properties/111', 'displayName' => 'example.com'],
                        ],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function pendingSession(Project $project): array
    {
        return [
            'google.oauth.state' => 'the-state',
            'google.oauth.verifier' => 'the-verifier',
            'google.oauth.project' => $project->getKey(),
        ];
    }
}
