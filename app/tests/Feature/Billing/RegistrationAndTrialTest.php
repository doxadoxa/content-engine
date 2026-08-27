<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\FakeBillingProvider;
use App\Billing\TrialEligibility;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Opening the door, and the three things that keep it from costing a fortune.
 *
 * A card-free trial is a marketing budget with a known worst case only for as
 * long as somebody cannot take it repeatedly. Every trial spends real model and
 * image calls — measured, about $2.83 — so a hundred signups is $283 and an
 * unbounded one is an open tab.
 */
final class RegistrationAndTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function anybody_can_create_an_account(): void
    {
        // This reverses §10 of the product spec — "an account exists because
        // someone created it" — and it should: that sentence describes an
        // operator tool, and this is what turns it into a service.
        $this->get('/register')->assertOk();

        $this->post('/register', [
            'name' => 'Alex Moreira',
            'email' => 'alex@example.test',
            'password' => 'a-strong-enough-password',
            'password_confirmation' => 'a-strong-enough-password',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'alex@example.test']);
    }

    #[Test]
    public function an_address_is_an_identity_rather_than_however_it_was_typed(): void
    {
        User::factory()->create(['email' => 'alex@example.test']);

        // Two spellings of one mailbox would otherwise be two accounts, which
        // is two free trials for the price of one shift key.
        $this->post('/register', [
            'name' => 'Someone Else',
            'email' => 'Alex@Example.test',
            'password' => 'a-strong-enough-password',
            'password_confirmation' => 'a-strong-enough-password',
        ])->assertSessionHasErrors('email');
    }

    #[Test]
    public function a_new_account_is_asked_to_prove_its_address(): void
    {
        Event::fake([Registered::class]);

        $this->post('/register', [
            'name' => 'Alex Moreira',
            'email' => 'alex@example.test',
            'password' => 'a-strong-enough-password',
            'password_confirmation' => 'a-strong-enough-password',
        ]);

        Event::assertDispatched(Registered::class);
        $this->assertNull(User::query()->sole()->email_verified_at);
    }

    #[Test]
    public function signing_up_is_throttled(): void
    {
        // Fortify reads `fortify.limiters` for login, two-factor, passkeys and
        // verification — and not for registration, whose route carries only
        // `guest:`. A limiter defined and never referenced is worse than none,
        // because it reads as a control that exists.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', [
                'name' => 'Alex Moreira',
                'email' => "alex{$i}@example.test",
                'password' => 'a-strong-enough-password',
                'password_confirmation' => 'a-strong-enough-password',
            ]);
        }

        $this->post('/register', [
            'name' => 'One Too Many',
            'email' => 'sixth@example.test',
            'password' => 'a-strong-enough-password',
            'password_confirmation' => 'a-strong-enough-password',
        ])->assertStatus(429);

        $this->assertNull(User::query()->where('email', 'sixth@example.test')->first());
    }

    #[Test]
    public function an_unverified_account_cannot_reach_the_wizard(): void
    {
        // The earliest honest place for this. The wizard's first step fetches
        // somebody's site and asks a model about it, so every screen of it
        // spends something.
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/onboarding')
            ->assertRedirect('/email/verify');
    }

    #[Test]
    public function a_verified_account_can(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/onboarding')
            ->assertOk();
    }

    #[Test]
    public function the_engine_will_not_start_for_an_unverified_address(): void
    {
        // Belt to the middleware's brace, and this is the one that matters:
        // launch is the line where money starts.
        $user = User::factory()->unverified()->create();
        $project = $this->draftFor($user);

        $this->actingAs($user)
            ->post("/onboarding/{$project->getKey()}/launch")
            ->assertRedirect();

        $this->assertSame(0, ProjectSubscription::query()->count());
    }

    #[Test]
    public function one_free_window_at_a_time_per_account(): void
    {
        // Belt-and-braces now rather than load-bearing: with a card taken at
        // the checkout, a second trial costs somebody a second card. It stays
        // because it costs nothing to keep.
        $user = User::factory()->create();

        $first = $this->draftFor($user);
        ProjectSubscription::factory()->forProject($first)->trialing()->create();

        $second = $this->draftFor($user, 'https://another-site.test');

        $this->actingAs($user)
            ->post("/onboarding/{$second->getKey()}/launch")
            ->assertRedirect();

        // One at a time rather than one ever: somebody who trialled last year,
        // subscribed, cancelled and came back with a different business is a
        // customer. Four trials running at once is the thing to stop.
        $this->assertNoCheckoutWasOpened();
    }

    #[Test]
    public function a_site_that_has_already_had_a_free_run_does_not_get_another(): void
    {
        $first = User::factory()->create();
        $firstProject = $this->draftFor($first, 'https://shop.example.test');
        ProjectSubscription::factory()->forProject($firstProject)->trialing()->create();

        // A different account entirely. Addresses are free and unlimited, so a
        // per-account rule alone is a rule about how much typing somebody is
        // willing to do — a domain had to be bought.
        $second = User::factory()->create();
        $secondProject = $this->draftFor($second, 'https://www.shop.example.test/');

        $this->actingAs($second)
            ->post("/onboarding/{$secondProject->getKey()}/launch")
            ->assertRedirect();

        $this->assertNoCheckoutWasOpened();
    }

    #[Test]
    public function a_different_site_on_the_same_account_is_fine_once_the_first_one_pays(): void
    {
        $user = User::factory()->create();

        $first = $this->draftFor($user);
        ProjectSubscription::factory()->forProject($first)->plan('medium')->create();

        // Paying rather than trialing. The account may now start a second
        // site — which is the whole shape of per-project billing.
        $second = $this->draftFor($user, 'https://another-site.test');

        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post("/onboarding/{$second->getKey()}/launch")
            ->assertStatus(409);

        $provider = app(BillingProvider::class);
        $this->assertInstanceOf(FakeBillingProvider::class, $provider);
        $this->assertSame($second->getKey(), $provider->checkouts[0]['project']);
    }

    #[Test]
    public function the_checkout_asks_for_a_card_and_charges_nothing_today(): void
    {
        $user = User::factory()->create();
        $project = $this->draftFor($user);

        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post("/onboarding/{$project->getKey()}/launch")
            ->assertStatus(409)
            ->assertHeader(
                'X-Inertia-Location',
                'https://checkout.stripe.test/medium/'.$project->getKey(),
            );

        // Nothing local is created by pressing the button. The subscription —
        // and with it the free window and its end date — arrives from Stripe.
        $this->assertSame(0, ProjectSubscription::query()->count());
    }

    #[Test]
    public function three_spellings_of_one_site_are_one_site(): void
    {
        foreach ([
            'https://example.test' => 'example.test',
            'https://www.example.test/pricing' => 'example.test',
            'HTTPS://WWW.Example.Test.' => 'example.test',
        ] as $url => $expected) {
            $this->assertSame($expected, TrialEligibility::hostOf($url));
        }
    }

    private function assertNoCheckoutWasOpened(): void
    {
        $provider = app(BillingProvider::class);
        $this->assertInstanceOf(FakeBillingProvider::class, $provider);
        $this->assertSame([], $provider->checkouts);
    }

    private function draftFor(User $user, string $url = 'https://example.test'): Project
    {
        $project = Project::factory()->onboarding()->unbilled()->create([
            'website_url' => $url,
            'site_analysis' => ['description' => 'A Lisbon cleaning business.'],
        ]);

        $user->projects()->attach($project, ['role' => 'owner']);

        return $project;
    }
}
