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
    public function a_system_administrator_can_view_horizon(): void
    {
        $this->assertTrue(
            Gate::forUser(User::factory()->create(['is_admin' => true]))->allows('viewHorizon'),
        );
    }

    #[Test]
    public function the_allow_list_is_not_a_second_way_in(): void
    {
        // It bootstraps the column and no longer answers the question. Left as
        // an alternative it kept every fault the move to `is_admin` was meant to
        // fix: an address on the list would still reach every tenant's failed
        // payloads after the flag had been revoked, and — now that anybody can
        // register — an account created later with such an address would
        // acquire the access without anybody granting it.
        $revoked = User::factory()->create([
            'email' => 'admin@example.test',
            'is_admin' => false,
        ]);

        config()->set('horizon.allowed_emails', ['admin@example.test']);

        $this->assertFalse(Gate::forUser($revoked)->allows('viewHorizon'));
    }

    #[Test]
    public function a_guest_may_not_view_horizon(): void
    {
        $this->assertFalse(Gate::allows('viewHorizon'));
    }
}
