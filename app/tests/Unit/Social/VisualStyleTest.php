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

    /**
     * The palette is the "lighter accent" the docblock used to ask a human for.
     *
     * Same brand as the two tests above — forest, cream, terracotta — where the
     * accent reads 2.22:1 on the fill and the 300px figure therefore falls back
     * to the cream. Give the brief the rest of the brand's colours and the
     * figure gets to be a colour again, without anybody retyping a field.
     */
    #[Test]
    public function a_stat_reaches_into_the_palette_before_giving_up_on_colour(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_colour' => '#2f4f43',
            'brand_ink' => '#f3efe6',
            'brand_accent' => '#d6533c',
            'brand_palette' => ['#df7866'],
        ]));

        // The accent itself is untouched: it still fills the rules and the ticks.
        $this->assertSame('#d6533c', $style->accent);

        // But the figure is now the brand's own lighter terracotta rather than
        // the cream every other word on the panel is already set in.
        $this->assertSame('#df7866', $style->accentType($style->colour));
    }

    /**
     * And with nothing to reach for, it degrades exactly as it always did.
     *
     * The regression guard for every brief written before the column existed:
     * an empty palette must leave the old path bit for bit, or adding a column
     * would have changed the look of work already published.
     */
    #[Test]
    public function an_empty_palette_degrades_to_the_ink_exactly_as_before(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_colour' => '#2f4f43',
            'brand_ink' => '#f3efe6',
            'brand_accent' => '#d6533c',
            'brand_palette' => [],
        ]));

        $this->assertSame([], $style->palette);
        $this->assertSame('#f3efe6', $style->accentType($style->colour));
    }

    /**
     * A brand whose accent already reads never consults the palette at all.
     *
     * The property that makes this safe to add to briefs that are drawing work
     * today: the list is only ever reached after the existing answer has failed
     * the floor, so a palette cannot change a panel that was already correct.
     */
    #[Test]
    public function an_accent_that_reads_is_kept_over_anything_in_the_palette(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_colour' => '#2f4f43',
            'brand_ink' => '#f3efe6',
            'brand_accent' => '#df7866',
            // Higher contrast on the forest than the accent, and still ignored.
            'brand_palette' => ['#ffffff'],
        ]));

        $this->assertSame('#df7866', $style->accentType($style->colour));
    }

    /**
     * The palette is read the way every other colour here is read.
     *
     * It is a column an operator edits and a seeder writes, so anything that is
     * not a hex is dropped rather than repaired — a bad value in this list would
     * otherwise reach a renderer as a fill nobody chose.
     */
    #[Test]
    public function the_palette_drops_anything_that_is_not_a_colour(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_colour' => '#2f4f43',
            'brand_ink' => '#f3efe6',
            'brand_accent' => '#d6533c',
            'brand_palette' => ['nonsense', '#DF7866', 'df7866', '#abc', ''],
        ]));

        // Normalised and de-duplicated: the same colour written three ways is
        // one colour, and the short form expands like everywhere else.
        $this->assertSame(['#df7866', '#aabbcc'], $style->palette);
    }

    /**
     * A face the renderer's image does not carry is refused, not passed through.
     *
     * The failure this prevents is the quiet one: Chromium falls back to
     * whatever it has rather than erroring, so a panel set in a missing family
     * renders in the wrong typeface and nothing reports it. Worse, it would look
     * right to whoever reviewed it on a machine with the font installed.
     */
    #[Test]
    public function a_typeface_the_renderer_does_not_carry_falls_back_to_the_house_face(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief([
            'brand_typeface' => 'comic-sans',
        ]));

        $this->assertSame(VisualStyle::DEFAULT_TYPEFACE, $style->typeface);
        $this->assertArrayHasKey($style->typeface, VisualStyle::TYPEFACES);
    }

    #[Test]
    public function a_bundled_typeface_is_the_one_the_panels_are_set_in(): void
    {
        $style = VisualStyle::fromBrief(new BrandBrief(['brand_typeface' => 'poppins']));

        $this->assertSame('poppins', $style->typeface);
        $this->assertSame('Poppins', VisualStyle::TYPEFACES[$style->typeface]);
    }

    /**
     * Every bundled face has the two weights the layouts actually set.
     *
     * The list and the files are declared in different places — a constant here,
     * a directory the Dockerfile copies wholesale — so nothing but a test
     * connects them. A face on the list with no files draws as a fallback and
     * reports success, which is the same silent failure as the case above
     * arrived at from the other end.
     */
    #[Test]
    public function every_offered_typeface_has_files_to_draw_with(): void
    {
        foreach (array_keys(VisualStyle::TYPEFACES) as $slug) {
            foreach ([400, 600] as $weight) {
                $this->assertFileExists(
                    resource_path("fonts/{$slug}/{$slug}-latin-{$weight}-normal.woff2"),
                    "{$slug} is offered but has no {$weight} weight to draw with.",
                );
            }
        }
    }

    /**
     * A brand that opens on its own colour is not offered a photograph.
     *
     * The decision is the brief's rather than the model's, and the reason is
     * structural: the copy is written before the picture exists, so a per-post
     * choice would be a bet on an image nobody has seen.
     */
    #[Test]
    public function a_brief_decides_whether_a_carousel_opens_on_its_photograph(): void
    {
        $this->assertSame(
            'photo',
            VisualStyle::fromBrief(new BrandBrief)->cover,
            'The default has to stay what every carousel already draws.',
        );

        $this->assertSame(
            'type',
            VisualStyle::fromBrief(new BrandBrief(['carousel_cover' => 'type']))->cover,
        );

        // Anything else is the house answer, on the same terms as every other
        // constrained field here: a renderer handed a value it does not know
        // draws nothing rather than something.
        $this->assertSame(
            'photo',
            VisualStyle::fromBrief(new BrandBrief(['carousel_cover' => 'collage']))->cover,
        );
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
