<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IntegrationProvider;
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
