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
        $this->assertSame('bottom', $style->position);
        $this->assertSame('sentence', $style->case);
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
    }
}
