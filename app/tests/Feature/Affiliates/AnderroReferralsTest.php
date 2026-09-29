<?php

declare(strict_types=1);

namespace Tests\Feature\Affiliates;

use App\Affiliates\Anderro;
use App\Affiliates\Exceptions\AffiliateEventRejected;
use App\Affiliates\Jobs\SendPaymentToAnderro;
use App\Affiliates\Jobs\SendSignupToAnderro;
use App\Billing\StripeWebhook;
use App\Models\AffiliateReferral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeGoogleProvider;
use Tests\TestCase;

/*
 * The affiliate programme, and the three ways it can be wrong.
 *
 * **A partner is not paid.** Every way of making an account has to report it —
 * the form and Google — or partners go uncredited for everybody who arrives
 * the way that was forgotten, and nothing anywhere would say so.
 *
 * **Somebody else's data is shared.** Anderro is told about a sign-up only when
 * a partner referred it and marketing consent stands, and about a payment only
 * when the sign-up was reported. A customer who found us on their own must
 * never reach a third party through this code.
 *
 * **A partner is paid twice.** Stripe sends two events for one paid invoice,
 * and delivers each of them at least once.
 */
final class AnderroReferralsTest extends TestCase
{
    use RefreshDatabase;

    private const string VISITOR = '0123456789abcdef0123456789abcdef';

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

        // Read at request time, because a second `Http::fake()` adds a stub
        // behind this one rather than replacing it.
        Http::fake(['track.anderro.com/*' => fn () => Http::response($this->body, $this->status)]);
    }

    #[Test]
    public function a_referred_sign_up_through_the_form_is_reported_with_its_visitor(): void
    {
        $this->withUnencryptedCookies($this->referredBrowser())->register();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === Anderro::ORIGIN.'/events'
            && $request->hasHeader('x-api-key', 'sk_test_secret')
            && $request['type'] === 'signup'
            && $request['customerEmail'] === 'alex@example.test'
            && $request['visitorId'] === self::VISITOR);

        $this->assertTrue($this->user('alex@example.test')->affiliateReferral?->mayReport());
    }

    #[Test]
    public function a_visitor_nobody_referred_is_not_reported(): void
    {
        // Consent given and the script running — but no partner link, so there
        // is nobody to credit and no reason to tell anybody the address.
        $cookies = $this->referredBrowser();
        unset($cookies['_anderro_ref']);

        $this->withUnencryptedCookies($cookies)->register();

        Http::assertNothingSent();
        $this->assertNull($this->user('alex@example.test')->affiliateReferral);
    }

    #[Test]
    public function cookies_that_outlived_their_consent_are_not_used(): void
    {
        // An answer to an older cookie inventory is no answer — consent.ts
        // reads it that way and so must the server.
        $this->withUnencryptedCookies($this->referredBrowser(version: '2020-01-01'))->register();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_refusal_of_marketing_is_respected_whatever_cookies_remain(): void
    {
        $this->withUnencryptedCookies($this->referredBrowser(marketing: false))->register();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_visitor_id_that_the_script_did_not_write_is_not_forwarded(): void
    {
        $this->withUnencryptedCookies([
            ...$this->referredBrowser(),
            '_anderro_vid' => '<script>alert(1)</script>',
        ])->register();

        Http::assertNothingSent();
    }

    #[Test]
    public function nothing_is_reported_by_an_installation_without_both_keys(): void
    {
        config(['services.anderro.secret_key' => null]);

        $this->withUnencryptedCookies($this->referredBrowser())->register();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_referred_sign_up_through_google_is_reported_too(): void
    {
        $this->fakeGoogle('sam@gmail.com');

        $this->withUnencryptedCookies($this->referredBrowser())
            ->get('/auth/google/callback?code=good&state=good')
            ->assertRedirect(config('fortify.home'));

        Http::assertSent(fn (Request $request): bool => $request['type'] === 'signup'
            && $request['customerEmail'] === 'sam@gmail.com'
            && $request['visitorId'] === self::VISITOR);

        $this->assertTrue($this->user('sam@gmail.com')->affiliateReferral?->mayReport());
    }

    #[Test]
    public function signing_in_with_google_to_an_existing_account_is_not_a_sign_up(): void
    {
        User::factory()->create(['email' => 'sam@gmail.com']);
        $this->fakeGoogle('sam@gmail.com');

        $this->withUnencryptedCookies($this->referredBrowser())
            ->get('/auth/google/callback?code=good&state=good')
            ->assertRedirect(config('fortify.home'));

        Http::assertNothingSent();
    }

    #[Test]
    public function a_referred_customer_s_paid_invoice_is_reported_before_tax(): void
    {
        $this->referredCustomer();

        $this->stripe($this->paidInvoice(amountPaid: 2_900 + 580, beforeTax: 2_900));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['type'] === 'payment'
            && $request['customerEmail'] === 'alex@example.test'
            && $request['amountCents'] === 2_900
            && ! isset($request['visitorId']));
    }

    #[Test]
    public function a_customer_nobody_referred_is_never_reported_when_they_pay(): void
    {
        User::factory()->create(['email' => 'alex@example.test', 'stripe_id' => 'cus_referred']);

        $this->stripe($this->paidInvoice());

        Http::assertNothingSent();
    }

    #[Test]
    public function a_paid_invoice_is_reported_once_however_stripe_delivers_it(): void
    {
        $this->referredCustomer();

        // The same event again, and then the other event Stripe sends for the
        // same money. Either one reported would pay the partner twice.
        $this->stripe($this->paidInvoice());
        $this->stripe($this->paidInvoice());
        $this->stripe($this->paidInvoice(type: 'invoice.payment_succeeded', eventId: 'evt_succeeded'));

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_free_trial_invoice_is_not_a_payment(): void
    {
        $this->referredCustomer();

        $this->stripe($this->paidInvoice(amountPaid: 0, beforeTax: 0));

        Http::assertNothingSent();
    }

    #[Test]
    public function a_refusal_that_will_not_change_is_not_retried(): void
    {
        [$this->status, $this->body] = [401, ['error' => 'Invalid API key']];
        $user = $this->referredCustomer();

        // Returns rather than throwing: a thrown exception is how a queued job
        // asks to be tried again, and a revoked key will be revoked next time.
        (new SendSignupToAnderro((int) $user->getKey()))->handle(app(Anderro::class));

        Http::assertSentCount(1);
    }

    #[Test]
    public function an_account_is_marked_referred_before_anderro_has_answered(): void
    {
        // A customer who skips the trial can pay while the signup is still
        // queued or being retried. The mark is what lets that payment through,
        // so it cannot wait for the job.
        Queue::fake();

        $this->withUnencryptedCookies($this->referredBrowser())->register();

        $user = $this->user('alex@example.test');

        Queue::assertPushed(SendSignupToAnderro::class, fn (SendSignupToAnderro $job): bool => $job->userId === $user->getKey());
        $this->assertSame(self::VISITOR, $user->affiliateReferral?->visitor_id);
        $this->assertTrue($user->affiliateReferral->mayReport());
    }

    #[Test]
    public function switching_marketing_off_stops_payments_being_reported(): void
    {
        $user = $this->referredCustomer();

        // The request the withdrawal's reload makes, carrying the new answer.
        $this->actingAs($user)
            ->withUnencryptedCookies($this->referredBrowser(marketing: false))
            ->get('/cookies')
            ->assertOk();

        $this->assertFalse($this->referralOf($user)->mayReport());

        $this->stripe($this->paidInvoice());

        Http::assertNothingSent();
    }

    #[Test]
    public function switching_marketing_off_while_signed_out_stops_payments_too(): void
    {
        $this->referredCustomer();

        // Signed out, on the browser the sign-up came from: what the teardown
        // in affiliates.ts posts before it deletes the visitor cookie.
        $this->withUnencryptedCookies($this->referredBrowser(marketing: false))
            ->post('/affiliates/withdraw')
            ->assertNoContent();

        $this->stripe($this->paidInvoice());

        Http::assertNothingSent();
    }

    #[Test]
    public function the_withdrawal_endpoint_needs_the_browser_to_have_actually_refused(): void
    {
        $user = $this->referredCustomer();

        // A browser that still allows marketing, and one whose answer is to an
        // inventory that has moved on. Neither has said no.
        $this->withUnencryptedCookies($this->referredBrowser())->post('/affiliates/withdraw')->assertNoContent();
        $this->withUnencryptedCookies($this->referredBrowser(marketing: false, version: '2020-01-01'))->post('/affiliates/withdraw')->assertNoContent();

        $this->assertTrue($this->referralOf($user)->mayReport());
    }

    #[Test]
    public function consent_that_has_lapsed_stops_payments_being_reported(): void
    {
        // Twelve months on, the browser has forgotten the answer and will ask
        // again. A renewal must not be reported on the strength of the old one.
        $this->referredCustomer(consentedAt: now()->subMonths(13));

        $this->stripe($this->paidInvoice());

        Http::assertNothingSent();
    }

    #[Test]
    public function a_new_cookie_inventory_stops_payments_until_it_is_accepted(): void
    {
        $user = $this->referredCustomer();

        config(['legal.consent_version' => '2027-01-01']);

        $this->stripe($this->paidInvoice());
        Http::assertNothingSent();

        // Accepted again, while signed in.
        $this->actingAs($user)->withUnencryptedCookies($this->referredBrowser())->get('/cookies')->assertOk();

        $this->stripe($this->paidInvoice(eventId: 'evt_after_reconsent'));
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_queued_payment_does_not_go_out_after_consent_is_withdrawn(): void
    {
        $user = $this->referredCustomer();

        Queue::fake();
        $this->stripe($this->paidInvoice());

        $job = null;
        Queue::assertPushed(SendPaymentToAnderro::class, function (SendPaymentToAnderro $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });

        // Withdrawn while the job waited in the queue.
        $this->withUnencryptedCookies($this->referredBrowser(marketing: false))->post('/affiliates/withdraw');

        $this->assertInstanceOf(SendPaymentToAnderro::class, $job);
        $job->handle(app(Anderro::class));

        $this->assertSame($user->getKey(), $job->userId);
        Http::assertNothingSent();
    }

    #[Test]
    public function payments_keep_the_address_the_sign_up_was_reported_under(): void
    {
        $user = $this->referredCustomer();

        // Anderro attributes by address, and only knows this one.
        $user->forceFill(['email' => 'alex@new-employer.test'])->save();

        $this->stripe($this->paidInvoice());

        Http::assertSent(fn (Request $request): bool => $request['type'] === 'payment'
            && $request['customerEmail'] === 'alex@example.test');
    }

    #[Test]
    public function only_a_current_refusal_counts_as_withdrawing(): void
    {
        $user = $this->referredCustomer();

        // Still allowed, then an answer to an inventory that has since moved
        // on — neither of which is somebody saying no.
        $this->actingAs($user)->withUnencryptedCookies($this->referredBrowser())->get('/cookies')->assertOk();
        $this->actingAs($user)->withUnencryptedCookies($this->referredBrowser(marketing: false, version: '2020-01-01'))->get('/cookies')->assertOk();

        $this->assertTrue($this->referralOf($user)->mayReport());
    }

    #[Test]
    public function a_bad_minute_at_anderro_is_retried(): void
    {
        [$this->status, $this->body] = [503, ['error' => 'Unavailable']];
        $user = $this->referredCustomer();

        $this->expectException(AffiliateEventRejected::class);

        (new SendSignupToAnderro((int) $user->getKey()))->handle(app(Anderro::class));
    }

    #[Test]
    public function the_page_carries_the_public_key_and_lets_the_browser_reach_anderro(): void
    {
        $response = $this->get('/');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $response->assertSee('<meta name="anderro-key" content="pk_test_public">', false);
        $response->assertDontSee('sk_test_secret', false);
        $this->assertMatchesRegularExpression('/script-src[^;]*https:\/\/track\.anderro\.com/', $csp);
        $this->assertMatchesRegularExpression('/connect-src[^;]*https:\/\/track\.anderro\.com/', $csp);
    }

    #[Test]
    public function an_installation_without_anderro_publishes_neither(): void
    {
        config(['services.anderro.public_key' => null]);

        $response = $this->get('/');

        $response->assertDontSee('anderro-key', false);
        $this->assertStringNotContainsString('anderro', (string) $response->headers->get('Content-Security-Policy'));
    }

    private function register(): void
    {
        $this->post('/register', [
            'name' => 'Alex Moreira',
            'email' => 'alex@example.test',
            'password' => 'a-strong-enough-password',
            'password_confirmation' => 'a-strong-enough-password',
        ])->assertRedirect();
    }

    /**
     * What a browser that arrived through a partner's link and allowed
     * marketing sends with every request. Plain, as the browser writes them:
     * none of the three is encrypted by us.
     *
     * @return array<string, string>
     */
    private function referredBrowser(bool $marketing = true, ?string $version = null): array
    {
        return [
            'avyo_consent' => (string) json_encode([
                'analytics' => false,
                'marketing' => $marketing,
                'v' => $version ?? (string) config('legal.consent_version'),
                'at' => now()->toIso8601String(),
            ]),
            '_anderro_vid' => self::VISITOR,
            '_anderro_ref' => 'partner-42',
        ];
    }

    /** A customer whose referred sign-up was reported, with marketing consent as it was then. */
    private function referredCustomer(?Carbon $consentedAt = null): User
    {
        $user = User::factory()->create([
            'email' => 'alex@example.test',
            'stripe_id' => 'cus_referred',
        ]);

        AffiliateReferral::query()->create([
            'user_id' => $user->getKey(),
            'visitor_id' => self::VISITOR,
            'email' => 'alex@example.test',
            'consent_version' => (string) config('legal.consent_version'),
            'consented_at' => $consentedAt ?? now(),
        ]);

        return $user;
    }

    private function referralOf(User $user): AffiliateReferral
    {
        return AffiliateReferral::query()->where('user_id', $user->getKey())->sole();
    }

    /** @return array<string, mixed> */
    private function paidInvoice(
        int $amountPaid = 2_900,
        ?int $beforeTax = null,
        string $type = 'invoice.paid',
        string $eventId = 'evt_paid',
    ): array {
        return [
            'id' => $eventId,
            'type' => $type,
            'created' => now()->getTimestamp(),
            'data' => ['object' => [
                'id' => 'in_referred',
                'customer' => 'cus_referred',
                'subscription' => 'sub_unknown',
                'amount_paid' => $amountPaid,
                'total_excluding_tax' => $beforeTax ?? $amountPaid,
            ]],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function stripe(array $payload): void
    {
        app(StripeWebhook::class)->handle($payload);
    }

    private function fakeGoogle(string $email): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.auth_redirect' => null,
        ]);

        $account = (new SocialiteUser)
            ->setRaw(['sub' => 'google-subject-1', 'email' => $email, 'email_verified' => true, 'name' => 'Sam Reyes'])
            ->map(['id' => 'google-subject-1', 'name' => 'Sam Reyes', 'email' => $email]);

        Socialite::extend('google', fn (): FakeGoogleProvider => new FakeGoogleProvider(request(), $account));
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->sole();
    }
}
