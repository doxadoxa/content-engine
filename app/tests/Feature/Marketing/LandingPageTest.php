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
}
