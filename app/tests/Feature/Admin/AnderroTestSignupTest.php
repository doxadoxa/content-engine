<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Affiliates\Anderro;
use App\Models\AdminAction;
use App\Models\AffiliateReferral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * An administrator checking the Anderro integration by hand.
 *
 * What matters is that the test is a test: sent to Anderro and written to the
 * admin log, but leaving no referral behind that a later payment would be
 * reported against — and reachable by nobody but an administrator, since it
 * hands a customer's address to a third party.
 */
final class AnderroTestSignupTest extends TestCase
{
    use RefreshDatabase;

    private const string VISITOR = '0123456789abcdef0123456789abcdef';

    private User $admin;

    private User $customer;

    /** What Anderro answers. Accepted unless a test says otherwise. */
    private int $status = 202;

    /** @var array<string, mixed> */
    private array $body = ['ok' => true];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anderro.public_key' => 'pk_test_public',
            'services.anderro.secret_key' => 'sk_test_secret',
        ]);

        Http::fake(['track.anderro.com/*' => fn () => Http::response($this->body, $this->status)]);

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create(['email' => 'sam@example.test']);
    }

    #[Test]
    public function the_signup_is_sent_now_with_the_visitor_typed_in(): void
    {
        $this->send(['visitor_id' => self::VISITOR])
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'success');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === Anderro::ORIGIN.'/events'
            && $request->hasHeader('x-api-key', 'sk_test_secret')
            && $request['type'] === 'signup'
            && $request['customerEmail'] === 'sam@example.test'
            && $request['visitorId'] === self::VISITOR);

        // A test must not leave the account looking referred: a payment
        // reported on the strength of it would pay a partner for nothing.
        $this->assertNull($this->customer->fresh()?->affiliateReferral);

        $action = AdminAction::query()->sole();
        $this->assertSame('affiliate.test_signup', $action->action);
        $this->assertSame($this->admin->getKey(), $action->user_id);
        $this->assertSame('accepted', $action->after['outcome']);
        $this->assertSame(self::VISITOR, $action->after['visitor_id']);
    }

    #[Test]
    public function a_referred_account_is_sent_with_the_visitor_it_was_referred_under(): void
    {
        AffiliateReferral::query()->create([
            'user_id' => $this->customer->getKey(),
            'visitor_id' => 'recorded-visitor-id',
            'email' => $this->customer->email,
        ]);

        $this->send()->assertRedirect();

        Http::assertSent(fn (Request $request): bool => $request['visitorId'] === 'recorded-visitor-id');
    }

    #[Test]
    public function without_a_visitor_to_hand_one_is_made_up(): void
    {
        $this->send()->assertRedirect();

        Http::assertSent(fn (Request $request): bool => preg_match('/\A[0-9a-f]{32}\z/', (string) $request['visitorId']) === 1);
    }

    #[Test]
    public function a_refusal_is_reported_back_and_logged(): void
    {
        $this->status = 401;
        $this->body = ['ok' => false, 'error' => 'invalid api key'];

        $this->send()
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'Anderro refused the event with HTTP 401: invalid api key');

        $this->assertStringStartsWith('rejected:', AdminAction::query()->sole()->after['outcome']);
    }

    #[Test]
    public function a_malformed_visitor_is_refused_before_anything_is_sent(): void
    {
        $this->send(['visitor_id' => 'not a visitor'])->assertSessionHasErrors('visitor_id');

        Http::assertNothingSent();
        $this->assertSame(0, AdminAction::query()->count());
    }

    #[Test]
    public function nothing_is_sent_when_anderro_is_not_configured(): void
    {
        config(['services.anderro.secret_key' => null]);

        $this->send()->assertRedirect()->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
    }

    #[Test]
    public function an_ordinary_operator_cannot_send_one(): void
    {
        $this->actingAs($this->customer)
            ->post("/admin/users/{$this->customer->getKey()}/anderro-signup")
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from('/admin/users')
            ->post("/admin/users/{$this->customer->getKey()}/anderro-signup", $data);
    }
}
