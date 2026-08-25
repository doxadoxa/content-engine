<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\ContentStudio\ContentStudioAssistant;
use App\Enums\SlideLayout;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Short fields are drawn onto a panel, so they cannot be cut like a caption.
 *
 * A carousel shipped a button labelled **"Save this guide before booking your
 * regu"** — forty characters exactly, the model having written a few more and
 * the parser having taken a knife to the middle of a word. In a caption an
 * overrun reads as a caption running on. Set in large type on a coloured
 * button, it reads as a broken product.
 *
 * Two halves to the fix and the order matters: the model is now told the
 * budgets, which is the actual remedy, and the trim is what happens when it
 * misses anyway.
 */
final class SlideFieldTest extends TestCase
{
    /** The bug, as a test. */
    #[Test]
    public function a_label_that_overruns_is_cut_at_a_word_and_never_inside_one(): void
    {
        $cut = $this->slideField('Save this guide before booking your regular clean', 'action', 40);

        $this->assertSame('Save this guide before booking your', $cut);
        $this->assertStringEndsNotWith('regu', $cut);
    }

    /** Trailing punctuation left dangling by the cut goes with it. */
    #[Test]
    public function the_cut_does_not_leave_a_dangling_comma(): void
    {
        $this->assertSame(
            'Book the visit',
            $this->slideField('Book the visit, then forget about it entirely', 'action', 20),
        );
    }

    /** What fits is untouched, including at exactly the limit. */
    #[Test]
    public function a_value_inside_its_budget_is_left_alone(): void
    {
        $this->assertSame('Save this guide', $this->slideField('Save this guide', 'action', 40));
        $this->assertSame('Book now', $this->slideField('  Book now  ', 'action', 8));
    }

    /**
     * A word and a half is not a shorter label, it is a broken one.
     *
     * Dropped instead, and the renderer draws the panel without it — the same
     * judgement the highlight field already gets when it cannot be matched.
     */
    #[Test]
    public function a_value_that_cannot_survive_the_cut_is_dropped(): void
    {
        $this->assertSame('', $this->slideField('Extraordinarily overlong single token here', 'action', 12));
        $this->assertSame('', $this->slideField('Supercalifragilisticexpialidocious', 'action', 10));
    }

    /**
     * The one field that must never be trimmed at all.
     *
     * Cutting "€1,250 per home" to "€1,250 per" is not a shorter number, and
     * cutting "1,250" to "1,2" states a different one. Twelve characters is
     * generous for a figure, so anything past it is a sentence in the wrong
     * field rather than a long number.
     */
    #[Test]
    public function a_figure_is_dropped_rather_than_shortened(): void
    {
        $this->assertSame('', $this->slideField('€1,250 per home visit', 'figure', 12));
        $this->assertSame('68%', $this->slideField('68%', 'figure', 12));
    }

    /**
     * And the model is told the numbers, from the array they are enforced from.
     *
     * Stated in no prompt and enforced in one place is how the button overran
     * in the first place.
     */
    #[Test]
    public function the_budgets_the_writer_is_told_are_the_ones_the_parser_uses(): void
    {
        $budgets = SlideLayout::budgets();

        $this->assertStringContainsString('action 40', $budgets);
        $this->assertStringContainsString('figure 12', $budgets);
        // `body` is 500 on a step and 300 on a cta; the tighter one is the safe
        // number to quote when one word has to cover both.
        $this->assertStringContainsString('body 300', $budgets);

        $method = new ReflectionMethod(ContentStudioAssistant::class, 'carouselContract');
        $method->setAccessible(true);

        /** @var string $contract */
        $contract = $method->invoke(app(ContentStudioAssistant::class));

        $this->assertStringContainsString($budgets, $contract);
    }

    private function slideField(mixed $value, string $field, int $limit): string
    {
        $method = new ReflectionMethod(ContentStudioAssistant::class, 'slideField');
        $method->setAccessible(true);

        /** @var string $text */
        $text = $method->invoke(app(ContentStudioAssistant::class), $value, $field, $limit);

        return $text;
    }
}
