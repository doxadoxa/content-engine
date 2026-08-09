<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SecurityBoundariesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function browser_responses_include_a_nonce_based_security_policy(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'nonce-", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+">/', (string) $response->getContent());
    }

    #[Test]
    public function project_membership_does_not_grant_global_horizon_access(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $project = Project::factory()->create();
        $user->projects()->attach($project, ['role' => 'owner']);

        config()->set('horizon.allowed_emails', ['admin@example.test']);

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
    }

    #[Test]
    public function explicitly_allowed_system_administrator_can_view_horizon(): void
    {
        $user = User::factory()->create(['email' => 'ADMIN@example.test']);

        config()->set('horizon.allowed_emails', ['admin@example.test']);

        $this->assertTrue(Gate::forUser($user)->allows('viewHorizon'));
    }
}
