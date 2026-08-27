<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LandingPageTest extends TestCase
{
    #[Test]
    public function it_shows_the_public_avyo_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('marketing'));
    }

    #[Test]
    public function the_prices_come_from_the_list_the_engine_bills_against(): void
    {
        // Not written into the page. There is one price list, and a second copy
        // of it in a marketing component is a second copy to forget — the sort
        // of thing found by a customer who was quoted one number and charged
        // another.
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('pricing.plans', 2)
                ->where('pricing.plans.1.key', 'medium')
                ->where('pricing.plans.1.price_cents', 9_900)
                ->where('pricing.plans.1.limits.articles', 30)
                ->where('pricing.trial_days', (int) config('billing.trial.days'))
                ->etc()
            );
    }

    #[Test]
    public function the_landing_page_offers_no_plan_nobody_can_buy(): void
    {
        // Enterprise is a conversation and a custom price. A card with a
        // "Choose" button under it would promise a checkout there is no code
        // for.
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pricing.plans.0.key', 'small')
                ->where('pricing.plans.1.key', 'medium')
                ->etc()
            );
    }
}
