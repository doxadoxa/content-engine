<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Content\HouseStyle;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rules in product/humanizated-articles.md, held to.
 *
 * Asked for in the writing step and checked here, because a rule that is asked
 * for and never checked is a suggestion.
 */
final class HouseStyleTest extends TestCase
{
    #[Test]
    public function the_em_dash_budget_is_two(): void
    {
        $style = new HouseStyle;

        $this->assertSame([], $style->violations('One — two — and no more.'));
        $this->assertSame(['4 em dashes'], $style->violations('A — b — c — d — e.'));
    }

    #[Test]
    public function the_banned_words_are_caught(): void
    {
        $style = new HouseStyle;

        $found = $style->violations('We delve into the realm of robust cleaning.');

        $this->assertNotEmpty($found);
        $this->assertStringContainsString('delve', $found[0]);
    }

    #[Test]
    public function a_word_that_merely_contains_a_banned_one_is_left_alone(): void
    {
        $style = new HouseStyle;

        // "foster" is banned; Fostering a child is not this rule's business,
        // and neither is a surname.
        $this->assertSame([], $style->violations('Mrs Fosterly cleaned the realms of glass.'));
    }

    #[Test]
    public function the_not_just_x_but_y_shape_is_caught(): void
    {
        $style = new HouseStyle;

        $found = $style->violations('This is not just a clean, but a reset.');

        $this->assertContains('a "not just X, but Y" construction', $found);
    }

    #[Test]
    public function a_section_naming_limits_is_recognised(): void
    {
        $style = new HouseStyle;

        $this->assertTrue($style->hasLimitations('## Where a weekly clean is not the right call'));
        $this->assertFalse($style->hasLimitations('## Benefits of cleaning'));
    }

    #[Test]
    public function uniform_sentences_fail_the_rhythm_check(): void
    {
        $style = new HouseStyle;

        // Ten sentences, all eight words long. Nothing is wrong with any of
        // them, and together they are the tell.
        $flat = str_repeat('The cleaner arrives and works through the whole flat. ', 10);

        $this->assertFalse($style->rhythmIsVaried($flat));
    }

    #[Test]
    public function prose_that_actually_varies_passes(): void
    {
        $style = new HouseStyle;

        $varied = 'Two hours. That is what a standard visit takes in a one-bedroom flat in '
            .'Alvalade, assuming the kitchen has been used normally and nobody has left a '
            .'week of dishes in the sink. Bathrooms take longest. A team of two will usually '
            .'finish a two-bedroom in the same window, because the work splits cleanly between '
            .'wet rooms and living space, and neither person waits on the other. Stone needs '
            .'care. Marble and limestone both etch on contact with anything acidic, which rules '
            .'out most supermarket bathroom sprays and every vinegar recipe on the internet. '
            .'We use pH-neutral. It costs more and it does not strip the seal.';

        $this->assertTrue($style->rhythmIsVaried($varied));
    }
}
