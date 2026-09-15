<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\OauthIdentity;
use App\Models\User;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeGoogleProvider;
use Tests\TestCase;
use Throwable;

/*
 * Signing in with Google.
 *
 * The cases worth a test here are the ones where "it worked when I tried it"
 * proves nothing, and there are three kinds:
 *
 * **Who ends up signed in.** Three inputs — an identity we have seen, an
 * address we already have an account for, and a stranger — must produce
 * exactly one account each, and the second must not produce a *second* account
 * under an address that already has one.
 *
 * **What is refused.** An unverified address must not be able to claim an
 * existing account, because that is the whole takeover: sign up at a sloppy
 * provider as somebody else's address and be handed their account. Google does
 * verify, so this test is guarding the code rather than Google — which is the
 * point, since the next provider is the one that will not.
 *
 * **What happens when it goes wrong.** A cancelled consent, an expired state,
 * and an unreachable provider are three different sentences on the sign-in
 * screen and none of them is a stack trace.
 */
final class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Present, so the buttons render and the routes do not refuse. Not
        // real: nothing in this file reaches Google.
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.auth_redirect' => null,
        ]);
    }

    #[Test]
    public function it_sends_people_to_google(): void
    {
        $response = $this->get('/auth/google/redirect');

        $response->assertRedirectContains('accounts.google.com');
        // The callback this application owns, not the one the project
        // integration uses — the two flows are two registered redirect URIs.
        $response->assertRedirectContains(urlencode(url('/auth/google/callback')));
    }

    #[Test]
    public function it_refuses_when_the_installation_has_no_google_client(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get('/auth/google/redirect')
            ->assertRedirect(route('login'))
            ->assertSessionHas('socialError');

        $this->get('/auth/google/callback?code=whatever')
            ->assertRedirect(route('login'))
            ->assertSessionHas('socialError');
    }

    #[Test]
    public function an_unknown_provider_is_a_404(): void
    {
        // The route binds the enum, so this never reaches the controller.
        $this->get('/auth/facebook/redirect')->assertNotFound();
    }

    #[Test]
    public function it_creates_a_verified_account_with_no_password(): void
    {
        $this->fakeGoogleReturns($this->googleUser());

        $response = $this->get('/auth/google/callback?code=good&state=good');

        $response->assertRedirect(config('fortify.home'));

        $user = User::query()->where('email', 'alex@gmail.com')->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Alex Moreira', $user->name);
        // Google proved the address; sending them to an inbox to prove it
        // again would be asking for the thing they just did.
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->hasPassword());

        $identity = OauthIdentity::query()->sole();
        $this->assertSame($user->getKey(), $identity->user_id);
        $this->assertSame('google-subject-1', $identity->provider_subject);
    }

    #[Test]
    public function it_lower_cases_the_address_before_it_becomes_an_identity(): void
    {
        $this->fakeGoogleReturns($this->googleUser(email: 'Alex@Example.com'));

        $this->get('/auth/google/callback?code=good&state=good');

        $this->assertSame('alex@example.com', User::query()->sole()->email);
    }

    #[Test]
    public function it_links_a_verified_address_to_the_account_that_already_has_it(): void
    {
        $existing = User::factory()->create(['email' => 'alex@gmail.com']);

        $this->fakeGoogleReturns($this->googleUser());

        $this->get('/auth/google/callback?code=good&state=good');

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, $existing->oauthIdentities()->count());
        // The password they already had is untouched: linking Google is a
        // second way in, not a replacement for the first.
        $this->assertTrue($existing->fresh()->hasPassword());
    }

    #[Test]
    public function linking_verifies_an_address_that_was_still_unverified(): void
    {
        $existing = User::factory()->unverified()->create(['email' => 'alex@gmail.com']);

        $this->fakeGoogleReturns($this->googleUser());

        $this->get('/auth/google/callback?code=good&state=good');

        $this->assertNotNull($existing->fresh()->email_verified_at);
    }

    #[Test]
    public function a_workspace_domain_google_asserts_is_authoritative_too(): void
    {
        $existing = User::factory()->create(['email' => 'alex@company.example']);

        $this->fakeGoogleReturns($this->googleUser(
            email: 'alex@company.example',
            hostedDomain: 'company.example',
        ));

        $this->get('/auth/google/callback?code=good&state=good');

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, $existing->oauthIdentities()->count());
    }

    #[Test]
    public function a_hosted_domain_for_some_other_domain_vouches_for_nothing(): void
    {
        User::factory()->create(['email' => 'alex@company.example']);

        // A `hd` is present, and it is not this address's domain. Accepting
        // the mere presence of the claim would let any Workspace account link
        // to any address it once verified.
        $this->fakeGoogleReturns($this->googleUser(
            email: 'alex@company.example',
            hostedDomain: 'somewhere-else.example',
        ));

        $this->get('/auth/google/callback?code=good&state=good')
            ->assertRedirect(route('login'))
            ->assertSessionHas('socialError');

        $this->assertGuest();
        $this->assertSame(0, OauthIdentity::query()->count());
    }

    #[Test]
    public function a_verified_but_unowned_address_cannot_take_over_an_existing_account(): void
    {
        // The address was verified at Google once, on a consumer account, and
        // has since been reassigned to somebody else who has an account here.
        // `email_verified` is true and means nothing about who holds the inbox
        // now, which is the whole reason ownership is asked separately.
        $victim = User::factory()->create(['email' => 'alex@company.example']);

        $this->fakeGoogleReturns($this->googleUser(email: 'alex@company.example'));

        $this->get('/auth/google/callback?code=good&state=good')
            ->assertRedirect(route('login'))
            ->assertSessionHas('socialError');

        $this->assertGuest();
        $this->assertSame(0, $victim->oauthIdentities()->count());
        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function a_verified_but_unowned_address_with_no_account_gets_one_and_has_to_prove_itself(): void
    {
        Notification::fake();

        $this->fakeGoogleReturns($this->googleUser(email: 'alex@company.example'));

        $this->get('/auth/google/callback?code=good&state=good');

        $user = User::query()->sole();

        // Signed in, because there is nobody to take the account from — but
        // not verified, because nothing here proved the inbox. That is the
        // ordinary email path, and it sends the ordinary email.
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    #[Test]
    public function an_authoritative_address_is_not_asked_to_prove_itself_again(): void
    {
        Notification::fake();

        $this->fakeGoogleReturns($this->googleUser());

        $this->get('/auth/google/callback?code=good&state=good');

        Notification::assertNothingSent();
    }

    #[Test]
    public function losing_the_race_to_create_the_account_still_signs_the_person_in(): void
    {
        // Two callbacks for the same new account, close enough together that
        // both looked and found nothing. This stands in for the other request:
        // it commits the row this one is about to insert, then fails this
        // insert the way Postgres would.
        $raced = false;

        Event::listen('eloquent.creating: '.User::class, function () use (&$raced): void {
            if ($raced) {
                return;
            }

            $raced = true;

            DB::table('users')->insert([
                'name' => 'Alex Moreira',
                'email' => 'alex@gmail.com',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            throw new UniqueConstraintViolationException(
                'pgsql', 'insert into users', [], new \PDOException('duplicate key value violates unique constraint')
            );
        });

        $this->fakeGoogleReturns($this->googleUser());

        $this->get('/auth/google/callback?code=good&state=good')
            ->assertRedirect(config('fortify.home'));

        $this->assertTrue($raced, 'The race was never triggered, so this proved nothing.');
        $this->assertAuthenticatedAs(User::query()->sole());
        $this->assertSame(1, OauthIdentity::query()->count());
    }

    #[Test]
    public function it_refuses_an_unverified_address_rather_than_handing_over_the_account(): void
    {
        User::factory()->create(['email' => 'alex@gmail.com']);

        $this->fakeGoogleReturns($this->googleUser(verified: false));

        $this->get('/auth/google/callback?code=good&state=good')
            ->assertRedirect(route('login'))
            ->assertSessionHas('socialError');

        $this->assertGuest();
        $this->assertSame(0, OauthIdentity::query()->count());
        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function it_refuses_when_google_shares_no_address(): void
    {
        $this->fakeGoogleReturns($this->googleUser(email: ''));

        $this->get('/auth/google/callback?code=good&state=good')
            ->assertRedirect(route('login'))
            ->assertSessionHas('socialError');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function a_returning_identity_signs_in_as_its_owner_whatever_the_address_now_is(): void
    {
        $user = User::factory()->create(['email' => 'alex@gmail.com']);
        $user->oauthIdentities()->create([
            'provider' => 'google',
            'provider_subject' => 'google-subject-1',
            'email' => 'alex@gmail.com',
        ]);

        // Same Google account, new address on it. The subject is what this
        // recognises; the address is only recorded.
        $this->fakeGoogleReturns($this->googleUser(email: 'alex@newjob.example'));

        $this->get('/auth/google/callback?code=good&state=good');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, OauthIdentity::query()->count());
        $this->assertSame('alex@newjob.example', OauthIdentity::query()->sole()->email);
        // The account keeps its own address: the one somebody signs in with
        // here is not changed by what they renamed themselves to at Google.
        $this->assertSame('alex@gmail.com', $user->fresh()->email);
    }

    #[Test]
    public function a_returning_identity_is_recognised_even_with_an_unverified_address(): void
    {
        $user = User::factory()->create();
        $user->oauthIdentities()->create([
            'provider' => 'google',
            'provider_subject' => 'google-subject-1',
            'email' => $user->email,
        ]);

        $this->fakeGoogleReturns($this->googleUser(verified: false));

        $this->get('/auth/google/callback?code=good&state=good');

        // Nothing is being decided from the address, so there is nothing for
        // the verified flag to protect against.
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_google_account_made_again_under_the_same_address_relinks(): void
    {
        $user = User::factory()->create(['email' => 'alex@gmail.com']);
        $user->oauthIdentities()->create([
            'provider' => 'google',
            'provider_subject' => 'the-deleted-one',
            'email' => 'alex@gmail.com',
        ]);

        // Same person, same verified address, new `sub`. A plain insert here
        // would collide with the one-identity-per-provider index and 500.
        $this->fakeGoogleReturns($this->googleUser(subject: 'the-new-one'));

        $this->get('/auth/google/callback?code=good&state=good');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, OauthIdentity::query()->count());
        $this->assertSame('the-new-one', OauthIdentity::query()->sole()->provider_subject);
    }

    #[Test]
    public function pressing_cancel_at_google_is_not_an_error(): void
    {
        $this->get('/auth/google/callback?error=access_denied')
            ->assertRedirect(route('login'))
            ->assertSessionMissing('socialError');

        $this->assertGuest();
    }

    #[Test]
    public function a_callback_that_did_not_start_here_is_refused(): void
    {
        $this->fakeGoogleFails(new InvalidStateException);

        $this->get('/auth/google/callback?code=good&state=forged')
            ->assertRedirect(route('login'))
            ->assertSessionHas('socialError');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function an_unreachable_google_is_a_sentence_rather_than_a_stack_trace(): void
    {
        $this->fakeGoogleFails(new RuntimeException('connection refused'));

        $this->get('/auth/google/callback?code=good&state=good')
            ->assertRedirect(route('login'))
            ->assertSessionHas('socialError');

        $this->assertGuest();
    }

    #[Test]
    public function the_entry_screens_offer_google_only_when_it_is_configured(): void
    {
        $this->get('/login')->assertInertia(
            fn (Assert $page) => $page->component('auth/login')
                ->where('socialProviders.0.key', 'google')
        );

        $this->get('/register')->assertInertia(
            fn (Assert $page) => $page->component('auth/register')
                ->where('socialProviders.0.key', 'google')
        );

        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get('/login')->assertInertia(
            fn (Assert $page) => $page->component('auth/login')->where('socialProviders', [])
        );
    }

    #[Test]
    public function an_account_with_no_password_cannot_be_signed_into_with_one(): void
    {
        $this->fakeGoogleReturns($this->googleUser());
        $this->get('/auth/google/callback?code=good&state=good');
        $this->post('/logout');

        // The framework refuses a null hash before it reaches the hasher; this
        // is here because the column becoming nullable is what made that
        // relevant, and a regression to a blank-string default would pass
        // every other test in this file.
        $this->post('/login', ['email' => 'alex@gmail.com', 'password' => ''])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    #[Test]
    public function a_session_alone_cannot_mint_a_password_for_a_passwordless_account(): void
    {
        $this->fakeGoogleReturns($this->googleUser());
        $this->get('/auth/google/callback?code=good&state=good');

        $user = User::query()->sole();

        // The interesting case, and the reason the rule is unconditional. A
        // borrowed session would otherwise leave behind a credential that
        // still works after the session is revoked.
        $this->put('/user/password', [
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertSessionHasErrors('current_password', errorBag: 'updatePassword');

        $this->assertFalse($user->fresh()->hasPassword());
    }

    #[Test]
    public function it_emails_a_first_password_link_and_ends_the_session(): void
    {
        Notification::fake();

        $this->fakeGoogleReturns($this->googleUser());
        $this->get('/auth/google/callback?code=good&state=good');

        $user = User::query()->sole();

        $this->post('/settings/password/link')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);

        // The link is the point of the whole exercise and Fortify puts it
        // behind `guest`, so a session left open here is a link that bounces
        // its own recipient to the dashboard.
        $this->assertGuest();
    }

    #[Test]
    public function the_emailed_link_actually_opens(): void
    {
        $this->fakeGoogleReturns($this->googleUser());
        $this->get('/auth/google/callback?code=good&state=good');

        $user = User::query()->sole();
        $token = Password::broker()->createToken($user);

        $this->post('/settings/password/link');

        // The assertion this whole fix exists for: signed out, the reset form
        // opens rather than redirecting away. Before the logout it answered a
        // 302 to the dashboard.
        $this->get('/reset-password/'.$token)->assertOk();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->hasPassword());
    }

    #[Test]
    public function it_does_not_email_a_link_or_sign_out_an_account_that_has_a_password(): void
    {
        Notification::fake();

        // A stale tab. Nothing sent, and nobody logged out for asking.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/password/link')
            ->assertRedirect();

        Notification::assertNothingSent();
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function the_password_screen_knows_which_of_the_two_forms_it_is(): void
    {
        $this->fakeGoogleReturns($this->googleUser());
        $this->get('/auth/google/callback?code=good&state=good');

        // No password: the screen asks for a first one and hides the
        // current-password field, matching the rule the action applies.
        $this->get('/settings/password')->assertInertia(
            fn (Assert $page) => $page->component('settings/password')->where('hasPassword', false)
        );

        $this->post('/logout');

        $this->actingAs(User::factory()->create())->get('/settings/password')->assertInertia(
            fn (Assert $page) => $page->component('settings/password')->where('hasPassword', true)
        );
    }

    #[Test]
    public function somebody_with_a_password_still_has_to_prove_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/user/password', [
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertSessionHasErrors('current_password', errorBag: 'updatePassword');
    }

    /** Socialite's answer, without Socialite talking to anybody. */
    private function fakeGoogleReturns(SocialiteUser $account): void
    {
        Socialite::extend('google', fn (): FakeGoogleProvider => new FakeGoogleProvider(request(), $account));
    }

    /** And what the controller does when the call to Google does not come back. */
    private function fakeGoogleFails(Throwable $failure): void
    {
        Socialite::extend('google', fn (): FakeGoogleProvider => new FakeGoogleProvider(request(), failure: $failure));
    }

    /**
     * Gmail by default, because that is the case Google is authoritative for
     * and most of the assertions below are about what happens when it is. The
     * `hostedDomain` argument is how a Workspace account says so instead.
     */
    private function googleUser(
        string $email = 'alex@gmail.com',
        bool $verified = true,
        string $subject = 'google-subject-1',
        string $name = 'Alex Moreira',
        ?string $hostedDomain = null,
    ): SocialiteUser {
        $user = new SocialiteUser;

        $raw = [
            'sub' => $subject,
            'email' => $email,
            'email_verified' => $verified,
            'name' => $name,
        ];

        if ($hostedDomain !== null) {
            $raw['hd'] = $hostedDomain;
        }

        return $user->setRaw($raw)->map([
            'id' => $subject,
            'name' => $name,
            'email' => $email,
        ]);
    }
}
