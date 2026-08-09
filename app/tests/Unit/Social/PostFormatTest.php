<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Support\Social\PostFormat;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §2's paragraph, as arithmetic — and specifically as arithmetic that reaches.
 *
 * > Работает: вопрос, личное наблюдение с фактурой, картинка с короткой
 * > подписью.
 *
 * The scale was written against that sentence and then calibrated against
 * nothing, so two of the three shapes could not clear the bar a throttled week
 * raises and the third could not clear the ordinary one by a single point. A
 * question topped out at 68, an observation with a figure at 64, and an image
 * with a short caption at 50 — because the third shape earned nothing at all —
 * against a throttled floor of 70. §4.3's "поднимает планку отбора" therefore
 * meant "publish nothing", and since `throttled_weekly_ceiling` equals
 * `weekly_floor` the same week went on to trip the floor alert by construction.
 *
 * So the test worth having is not "does the scorer prefer a question": it is
 * that every shape the spec recommends can actually be published, and that
 * raising the bar makes the engine pickier rather than mute.
 */
final class PostFormatTest extends TestCase
{
    /** §2's first shape, with nothing else going for it. */
    private const string QUESTION = 'What do you actually use on limescale in a kettle?';

    /** §2's second: one personal observation with a figure in it. */
    private const string OBSERVATION = 'We repainted the same stairwell three times last winter because '
        .'the salt kept coming back through the render. Four coats, in the end.';

    /** §2's third: a caption, the length you would write under a photograph. */
    private const string CAPTION = 'Two hours of scrubbing and the grout is still grey. Some things you '
        .'just replace.';

    #[Test]
    public function each_of_the_three_shapes_that_work_clears_the_ordinary_bar(): void
    {
        $ordinary = (int) config('social.governor.selection_floor');

        $this->assertGreaterThanOrEqual($ordinary, PostFormat::score([self::QUESTION]));
        $this->assertGreaterThanOrEqual($ordinary, PostFormat::score([self::OBSERVATION]));
        $this->assertGreaterThanOrEqual($ordinary, PostFormat::score([self::CAPTION]));
    }

    #[Test]
    public function the_raised_bar_of_a_weak_week_still_admits_what_section_two_recommends(): void
    {
        $raised = (int) config('social.governor.throttled_selection_floor');

        // All three, and not merely one: a bar that admitted only the question
        // would leave a project whose material is prices and photographs with
        // nothing publishable for as long as the throttle lasted, which is the
        // same silence in a smaller costume.
        $this->assertGreaterThanOrEqual($raised, PostFormat::score([self::QUESTION]));
        $this->assertGreaterThanOrEqual($raised, PostFormat::score([self::OBSERVATION]));
        $this->assertGreaterThanOrEqual($raised, PostFormat::score([self::CAPTION]));
    }

    #[Test]
    public function the_raised_bar_still_refuses_the_merely_inoffensive(): void
    {
        // What "pickier" has to mean if it means anything: a post with nothing
        // wrong with it and nothing §2 says works. Long enough not to be a
        // caption, short enough not to be a lecture, no question, no figure.
        $bland = 'Cleaning a stairwell is one of those jobs that looks finished long before it is, and '
            .'the difference only shows up once the light changes in the afternoon and everybody walks '
            .'past it on the way in. We think about that more than we probably should, honestly.';

        $score = PostFormat::score([$bland]);

        $this->assertSame(PostFormat::BASE, $score);
        $this->assertGreaterThanOrEqual((int) config('social.governor.selection_floor'), $score);
        $this->assertLessThan((int) config('social.governor.throttled_selection_floor'), $score);
    }

    #[Test]
    public function the_raised_bar_is_the_ordinary_one_plus_the_smallest_thing_that_works(): void
    {
        // The two numbers live in different files — the bar in config, the
        // shapes in the scorer — and the relationship between them is the whole
        // definition of a throttled week. Left as a comment it drifted once
        // already, into a bar no single shape could reach.
        $this->assertSame(PostFormat::BASE, (int) config('social.governor.selection_floor'));

        $this->assertSame(
            PostFormat::BASE + min(PostFormat::QUESTION, PostFormat::SUBSTANCE, PostFormat::CAPTION),
            (int) config('social.governor.throttled_selection_floor'),
        );
    }

    #[Test]
    public function the_shapes_that_do_not_work_are_still_under_the_ordinary_bar(): void
    {
        $ordinary = (int) config('social.governor.selection_floor');

        // «Тред-эссе», unjustified: 50 + 18 − 25.
        $this->assertLessThan($ordinary, PostFormat::score([self::QUESTION, 'And another thing.']));

        // «Голая ссылка», with nothing around it.
        $this->assertLessThan($ordinary, PostFormat::score(['https://example.test/prices']));
    }

    #[Test]
    public function a_caption_is_one_short_segment_with_nothing_to_click(): void
    {
        $this->assertTrue(PostFormat::isCaption([self::CAPTION]));

        // A link makes it a link post, which §2 scores by its own clause. Left
        // in, the bonus would land on the bare link — short by definition, and
        // the one shape §2 gives no working version of.
        $this->assertFalse(PostFormat::isCaption(['Look at this. https://example.test/prices']));
        $this->assertFalse(PostFormat::isCaption([self::CAPTION], 'https://example.test/prices'));

        // A chain is not a caption for a photograph.
        $this->assertFalse(PostFormat::isCaption([self::CAPTION, 'One more thought.']));

        $this->assertFalse(PostFormat::isCaption([str_repeat('a', PostFormat::CAPTION_CHARS + 1)]));
    }

    #[Test]
    public function a_year_on_its_own_is_not_a_figure(): void
    {
        // §2's second shape is «личное наблюдение с фактурой». A hot take that
        // happens to name the year has no фактура in it, and before this the
        // year bought it the same 14 points as a price.
        $this->assertFalse(PostFormat::hasSubstance('Everyone will have given up on this by 2026.'));

        // What an actual date looks like: the day survives the year going.
        $this->assertTrue(PostFormat::hasSubstance('We changed the rate on 8 August 2026.'));
        $this->assertTrue(PostFormat::hasSubstance('Water is 40% dearer than last winter.'));

        // A quantity that happens to look like a year is still a quantity, so
        // the pattern only removes a number standing on its own.
        $this->assertTrue(PostFormat::hasSubstance('The tank holds 20260 litres.'));
    }
}
