<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Affiliates\Anderro;
use App\Models\AdminAction;
use App\Models\AffiliateReferral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * An administrator checking that payments reach Anderro, by hand.
 *
 * Sent under the address Anderro attributes by, for the amount typed in, and
 * written to the admin log — and, like the test signup, leaving nothing of ours
 * changed and reachable by nobody but an administrator.
 */
final class AnderroTestPaymentTest extends TestCase
{
    use RefreshDatabase;

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
    public function the_payment_is_sent_now_in_cents(): void
    {
        $this->send(['amount' => '19.99'])
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Anderro accepted a payment of 19.99 for sam@example.test.');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === Anderro::ORIGIN.'/events'
            && $request->hasHeader('x-api-key', 'sk_test_secret')
            && $request['type'] === 'payment'
            && $request['customerEmail'] === 'sam@example.test'
            && $request['amountCents'] === 1999);

        $this->assertNull($this->customer->fresh()?->affiliateReferral);

        $action = AdminAction::query()->sole();
        $this->assertSame('affiliate.test_payment', $action->action);
        $this->assertSame($this->admin->getKey(), $action->user_id);
        $this->assertSame(1999, $action->after['amount_cents']);
        $this->assertSame('accepted', $action->after['outcome']);
    }

    #[Test]
    public function a_referred_account_is_paid_under_the_address_its_signup_was_reported_with(): void
    {
        AffiliateReferral::query()->create([
            'user_id' => $this->customer->getKey(),
            'visitor_id' => 'recorded-visitor-id',
            'email' => 'sam-at-signup@example.test',
        ]);

        $this->send(['amount' => '5'])->assertRedirect();

        Http::assertSent(fn (Request $request): bool => $request['customerEmail'] === 'sam-at-signup@example.test'
            && $request['amountCents'] === 500);
    }

    #[Test]
    public function the_accounts_list_says_which_accounts_a_partner_referred(): void
    {
        AffiliateReferral::query()->create([
            'user_id' => $this->customer->getKey(),
            'visitor_id' => 'recorded-visitor-id',
            'email' => $this->customer->email,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/users?q=sam')
            ->assertInertia(fn ($page) => $page->where('users.data.0.referred', true));
    }

    #[Test]
    public function a_refusal_is_reported_back_and_logged(): void
    {
        $this->status = 422;
        $this->body = ['ok' => false, 'error' => 'amountCents must be positive'];

        $this->send(['amount' => '1'])
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'Anderro refused the event with HTTP 422: amountCents must be positive');

        $this->assertStringStartsWith('rejected:', AdminAction::query()->sole()->after['outcome']);
    }

    #[Test]
    public function no_answer_warns_against_sending_it_again(): void
    {
        Http::fake(['track.anderro.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $this->send(['amount' => '1'])
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'warning');

        $this->assertSame('unknown: no answer from Anderro', AdminAction::query()->sole()->after['outcome']);
    }

    #[Test]
    public function an_amount_anderro_would_refuse_is_refused_before_anything_is_sent(): void
    {
        foreach (['', '0', '-5', '1.234', 'ten'] as $amount) {
            $this->send(['amount' => $amount])->assertSessionHasErrors('amount');
        }

        Http::assertNothingSent();
        $this->assertSame(0, AdminAction::query()->count());
    }

    #[Test]
    public function nothing_is_sent_when_anderro_is_not_configured(): void
    {
        config(['services.anderro.secret_key' => null]);

        $this->send(['amount' => '1'])->assertRedirect()->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
    }

    #[Test]
    public function an_ordinary_operator_cannot_send_one(): void
    {
        $this->actingAs($this->customer)
            ->post("/admin/users/{$this->customer->getKey()}/anderro-payment", ['amount' => '1'])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(array $data): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from('/admin/users')
            ->post("/admin/users/{$this->customer->getKey()}/anderro-payment", $data);
    }
}
