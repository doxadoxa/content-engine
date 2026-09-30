<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A verification link survives the trip through the edge.
 *
 * Production: Cloudflare speaks https to the browser and plain http onwards,
 * and the proxy in front of PHP says so in `X-Forwarded-Proto`. The link is
 * signed by a queue worker from APP_URL, as `https://…`. It used to be checked
 * against the request's own idea of its URL, `http://…`, and every one of them
 * answered 403 "Invalid signature".
 *
 * The suite's requests come from 127.0.0.1, which is a trusted proxy by
 * default, so the forwarded headers here are honoured exactly as they are in
 * production.
 */
final class SignedLinksBehindEdgeTest extends TestCase
{
    use RefreshDatabase;

    private const EDGE = [
        'X-Forwarded-Proto' => 'http',
        'X-Forwarded-Port' => '80',
    ];

    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
        URL::forceScheme(null);

        parent::tearDown();
    }

    #[Test]
    public function a_link_signed_as_https_is_accepted_when_the_edge_reports_http(): void
    {
        $user = User::factory()->unverified()->create();
        $link = $this->verificationLink($user, 'https', 'app.avyo.test');

        $this->actingAs($user)
            ->withHeaders(self::EDGE)
            ->get(str_replace('https://', 'http://', $link))
            ->assertRedirect();

        $this->assertTrue($user->fresh()?->hasVerifiedEmail());
    }

    #[Test]
    public function a_tampered_link_is_still_refused(): void
    {
        // Making the request https must not make the check lenient.
        $user = User::factory()->unverified()->create();
        $link = $this->verificationLink($user, 'https', 'app.avyo.test');

        $this->actingAs($user)
            ->withHeaders(self::EDGE)
            ->get(str_replace('https://', 'http://', $link).'0')
            ->assertForbidden();

        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    #[Test]
    public function localhost_is_still_served_over_http(): void
    {
        // `docker compose up` and the rest of the suite: an http link, signed
        // and checked as http.
        $user = User::factory()->unverified()->create();
        $link = $this->verificationLink($user, 'http', 'localhost');

        $this->actingAs($user)->get($link)->assertRedirect();

        $this->assertTrue($user->fresh()?->hasVerifiedEmail());
    }

    /** Signed the way the queue worker signs it: from the configured root. */
    private function verificationLink(User $user, string $scheme, string $host): string
    {
        URL::forceRootUrl("{$scheme}://{$host}");
        URL::forceScheme($scheme);

        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        URL::forceRootUrl(null);
        URL::forceScheme(null);

        return $link;
    }
}
