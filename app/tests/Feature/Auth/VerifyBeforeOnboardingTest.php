<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proving the address first, then setting up the business.
 *
 * The wizard has always been behind `verified`. What was wrong was the order a
 * new customer met things in: signing up landed on Home, Home offered "Set up
 * your business", and that button bounced them to "go and read your email".
 * These pin the order down: inbox, then wizard.
 */
final class VerifyBeforeOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function signing_up_goes_straight_to_the_inbox_prompt(): void
    {
        $this->post('/register', [
            'name' => 'Alex Moreira',
            'email' => 'alex@example.test',
            'password' => 'a-strong-enough-password',
            'password_confirmation' => 'a-strong-enough-password',
        ])->assertRedirect('/email/verify');
    }

    #[Test]
    public function the_prompt_shows_the_address_the_link_went_to(): void
    {
        $this->actingAs(User::factory()->unverified()->create(['email' => 'alex@courlty.cloud']))
            ->get('/email/verify')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/verify-email')
                ->where('email', 'alex@courlty.cloud'));
    }

    #[Test]
    public function home_does_not_offer_the_wizard_to_an_unproved_address(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/home')
            ->assertRedirect('/email/verify');
    }

    #[Test]
    public function home_still_offers_it_once_the_address_is_proved(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('project', null)
                ->where('hasProjects', false));
    }

    #[Test]
    public function somebody_re_proving_a_changed_address_keeps_their_work(): void
    {
        // Changing an address empties `email_verified_at`. That must not lock
        // somebody out of a business they already run.
        $user = User::factory()->unverified()->create();
        $user->projects()->attach(Project::factory()->create());

        $this->actingAs($user)->get('/home')->assertOk();
    }

    #[Test]
    public function the_address_can_be_corrected_before_it_is_proved(): void
    {
        // A typo in the domain is the likeliest reason the link never comes,
        // and the prompt links here to fix it.
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/settings/profile')
            ->assertOk();
    }

    #[Test]
    public function the_link_in_the_mail_lands_in_the_wizard(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get($this->verificationUrl($user))
            ->assertRedirect('/onboarding?verified=1');

        $this->assertTrue($user->fresh()?->hasVerifiedEmail());
    }

    #[Test]
    public function the_link_lands_at_home_for_somebody_who_already_has_a_business(): void
    {
        $user = User::factory()->unverified()->create();
        $user->projects()->attach(Project::factory()->create());

        $this->actingAs($user)
            ->get($this->verificationUrl($user))
            ->assertRedirect('/home?verified=1');
    }

    #[Test]
    public function the_link_returns_somebody_to_where_they_were_headed(): void
    {
        // `/start?plan=…` for a signed-in account goes to the wizard, which
        // sends an unproved one to the prompt and remembers where it was.
        $user = User::factory()->unverified()->create();
        $user->projects()->attach(Project::factory()->create());

        $this->actingAs($user)->get('/onboarding')->assertRedirect('/email/verify');

        $this->get($this->verificationUrl($user))->assertRedirect('/onboarding');
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }
}
