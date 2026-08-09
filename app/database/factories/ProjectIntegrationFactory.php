<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Integrations\Threads\ThreadsConnection;
use App\Models\ProjectIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectIntegration>
 */
class ProjectIntegrationFactory extends Factory
{
    protected $model = ProjectIntegration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => IntegrationProvider::Google,
            'refresh_token' => 'refresh-token',
            'access_token' => 'access-token',
            // Live by default: a test that wants an expired one says so, and
            // the common case should not need saying.
            'access_token_expires_at' => now()->addHour(),
            'scopes' => [
                ProjectIntegration::SCOPE_SEARCH_CONSOLE,
                ProjectIntegration::SCOPE_ANALYTICS,
            ],
            'config' => [
                'search_console_site' => 'sc-domain:example.com',
                'analytics_property' => 'properties/123456789',
            ],
            'connected_at' => now(),
        ];
    }

    /**
     * A connected Threads account (§9).
     *
     * `refresh_token` stays null and the long-lived token lives in
     * `access_token`, which is the arrangement {@see ThreadsConnection} explains
     * at length: Threads has one credential that renews by presenting itself,
     * and copying it into both columns would mean two encrypted copies of one
     * secret drifting apart on every renewal. The expiry is far out so that
     * nothing in a publishing test accidentally exercises the renewal path.
     */
    public function threads(): static
    {
        return $this->state(fn (): array => [
            'provider' => IntegrationProvider::Threads,
            'refresh_token' => null,
            'access_token' => 'threads-long-lived-token',
            'access_token_expires_at' => now()->addDays(60),
            'scopes' => (array) config('services.threads.scopes'),
            // Both halves of what the callback writes. The username is not
            // decoration: it is what tells a reply the account itself wrote
            // from a reply somebody sent us, and a fixture without it would
            // exercise only the branch where that question cannot be answered.
            'config' => ['user_id' => '17841400000000000', 'username' => 'brandname'],
            'connected_at' => now(),
        ]);
    }

    /** Connected, but nothing chosen yet — the state right after the callback. */
    public function unchosen(): static
    {
        return $this->state(fn (): array => ['config' => []]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['access_token_expires_at' => now()->subMinute()]);
    }

    /** Google has stopped honouring the grant. */
    public function broken(): static
    {
        return $this->state(fn (): array => [
            'failure_reason' => 'Google no longer accepts this connection.',
            'access_token' => null,
            'access_token_expires_at' => null,
        ]);
    }

    /** Only Search Console was granted — the operator unticked Analytics. */
    public function searchOnly(): static
    {
        return $this->state(fn (): array => [
            'scopes' => [ProjectIntegration::SCOPE_SEARCH_CONSOLE],
            'config' => ['search_console_site' => 'sc-domain:example.com'],
        ]);
    }
}
