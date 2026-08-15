<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Models\BrandBrief;
use App\Support\Brand\VisualStyle;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class VisualStyleTest extends TestCase
{
    #[Test]
    public function a_brand_that_has_never_opened_the_form_still_renders(): void
    {
        $style = VisualStyle::fromBrief(null);

        $this->assertSame(VisualStyle::DEFAULT_COLOUR, $style->colour);
        $this->assertSame(VisualStyle::DEFAULT_INK, $style->ink);
        $this->assertSame(VisualStyle::DEFAULT_INK, $style->accent);
        $this->assertSame('bottom', $style->position);
        $this->assertSame('sentence', $style->case);
    }

    /**
     * An unset accent is the ink, which is what every carousel already did.
     *
     * The load-bearing case of the whole field. `CarouselPanels` passed the ink
     * as the accent from the day panels existed, so a default that resolved to
     * anything else would have restyled every brand's carousels the moment the
     * migration ran — a schema change quietly changing what published.
     */
    #[Test]
    public function a_brand_that_has_not_named_an_accent_emphasises_in_its_own_ink(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_colour' => '#2f4f43',
            'brand_ink' => '#f3efe6',
            'brand_accent' => VisualStyle::DEFAULT_ACCENT,
        ]));

        // The brand's cream, not the house white. A brand with a cream ink
        // wants cream emphasis; #ffffff beside #f3efe6 reads as a mistake.
        $this->assertSame('#f3efe6', $style->accent);
    }

    #[Test]
    public function an_accent_the_brand_did_name_is_the_one_that_is_drawn(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_ink' => '#f3efe6',
            'brand_accent' => 'D6533C',
        ]));

        $this->assertSame('#d6533c', $style->accent);
    }

    #[Test]
    public function a_half_typed_accent_falls_back_to_the_ink_rather_than_to_white(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_ink' => '#f3efe6',
            'brand_accent' => '#d65',
        ]));

        // Three digits is shorthand and expands. Anything shorter is somebody
        // mid-keystroke, and the panel it would draw is worse than no accent.
        $this->assertSame('#dd6655', $style->accent);

        $this->assertSame(
            '#f3efe6',
            VisualStyle::fromBrief(new BrandBrief([
                'brand_ink' => '#f3efe6',
                'brand_accent' => '#d6',
            ]))->accent,
        );
    }

    #[Test]
    public function a_half_typed_colour_falls_back_rather_than_drawing_a_black_box(): void
    {
        // The normal state of a form field somebody is still typing into. A
        // renderer handed this either throws in a worker or paints black, and
        // both are worse than a panel in the house colour.
        $style = VisualStyle::fromBrief(new BrandBrief(['brand_colour' => '#12']));

        $this->assertSame(VisualStyle::DEFAULT_COLOUR, $style->colour);
    }

    #[Test]
    public function shorthand_is_expanded_because_that_is_what_people_type(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_colour' => '#FFF',
            'brand_ink' => '1A2B3C',
        ]));

        $this->assertSame('#ffffff', $style->colour);
        $this->assertSame('#1a2b3c', $style->ink);
    }

    #[Test]
    public function a_position_or_case_outside_the_set_is_ignored(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'overlay_position' => 'diagonal',
            'overlay_case' => 'sPoNgEbOb',
        ]));

        $this->assertSame('bottom', $style->position);
        $this->assertSame('sentence', $style->case);
    }

    #[Test]
    public function the_case_rule_is_applied_to_the_words_themselves(): void
    {
        $sentence = VisualStyle::fromBrief(new BrandBrief(['overlay_case' => 'sentence']));
        $upper = VisualStyle::fromBrief(new BrandBrief(['overlay_case' => 'upper']));

        $this->assertSame('Deep clean, done right', $sentence->write('  Deep clean, done right '));
        $this->assertSame('DEEP CLEAN, DONE RIGHT', $upper->write('Deep clean, done right'));
    }

    /**
     * Type is never drawn below the legibility floor, whatever the brand picked.
     *
     * Both failures were real and both shipped on the first accented render.
     * Cleaning Point's forest on its terracotta is 2.22:1, which made the CTA —
     * the one slide asking for the follow — the least readable of the seven; and
     * its terracotta on its forest is also 2.22:1, so the 300px figure that
     * exists to be believed was the most washed-out thing on the carousel.
     */
    #[Test]
    public function type_on_the_accent_is_whichever_colour_can_actually_be_read(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_colour' => '#2f4f43',
            'brand_ink' => '#f3efe6',
            'brand_accent' => '#d6533c',
        ]));

        $this->assertEqualsWithDelta(2.22, $style->contrast('#2f4f43', '#d6533c'), 0.01);
        $this->assertEqualsWithDelta(3.55, $style->contrast('#f3efe6', '#d6533c'), 0.01);

        // The cream, not the forest the layout would otherwise have inverted to.
        $this->assertSame('#f3efe6', $style->readableOn($style->accent));
    }

    #[Test]
    public function an_accent_too_close_to_the_fill_keeps_its_graphics_and_loses_its_type(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_colour' => '#2f4f43',
            'brand_ink' => '#f3efe6',
            'brand_accent' => '#d6533c',
        ]));

        // Still the accent everywhere contrast is not a legibility question —
        // the rule, the ticks, the filled half of a comparison.
        $this->assertSame('#d6533c', $style->accent);

        // But not as a 300px figure on the brand's own fill.
        $this->assertSame('#f3efe6', $style->accentType($style->colour));
    }

    #[Test]
    public function an_accent_that_carries_type_is_used_as_type(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_colour' => '#2f4f43',
            'brand_ink' => '#f3efe6',
            // The same hue lightened until it clears the floor on the forest.
            'brand_accent' => '#df7866',
        ]));

        $this->assertGreaterThanOrEqual(
            VisualStyle::MIN_CONTRAST,
            $style->contrast('#df7866', '#2f4f43'),
        );
        $this->assertSame('#df7866', $style->accentType($style->colour));
    }

    #[Test]
    public function a_colour_comes_apart_into_channels_a_renderer_can_use(): void
    {
        $style = VisualStyle::fallback();

        $this->assertSame([26, 26, 46], $style->rgb('#1a1a2e'));
        $this->assertSame([255, 255, 255], $style->rgb('ffffff'));
    }

    #[Test]
    public function clearing_a_visual_field_restores_the_house_value(): void
    {
        // Blanking a colour in a form means "undo my choice", not "no colour".
        // Every other cleared field in this table becomes an empty string, and
        // an empty string is not something a renderer can fill with.
        $this->assertContains('brand_colour', BrandBrief::VISUAL_FIELDS);
        $this->assertContains('brand_colour', BrandBrief::CONTENT_FIELDS);

        // The accent among them, so changing it makes a new brief version like
        // any other edit — which is what lets a post published last month say
        // what colour it was emphasised in.
        $this->assertContains('brand_accent', BrandBrief::VISUAL_FIELDS);
        $this->assertContains('brand_accent', BrandBrief::CONTENT_FIELDS);
    }
}
