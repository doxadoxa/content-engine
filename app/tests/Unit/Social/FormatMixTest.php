<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Enums\ChannelType;
use App\Enums\ContentFormat;
use App\Enums\PostKind;
use App\Support\Social\ContentMix;
use App\Support\Social\FormatMix;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A month is a mix of artefacts as well as of subjects.
 *
 * Measured before this existed: **zero carousels across every month the engine
 * had ever planned**, with a renderer standing by that draws panels with real
 * type. Nothing set `content_format`, so every idea fell through to a rule that
 * gives a carousel to a how-to and one photograph to everything else.
 *
 * The failure runs both ways, which is why a ceiling sits beside the target: a
 * competing tool handed four of these ideas made all four five-slide carousels.
 */
final class FormatMixTest extends TestCase
{
    /** The failure this was written for, stated as a refusal. */
    #[Test]
    public function a_month_with_no_carousel_at_all_is_refused_with_a_reason(): void
    {
        $findings = FormatMix::fromConfig()->findings($this->month(carousels: 0));

        $this->assertNotSame([], $findings);
        $this->assertStringContainsString('no carousel at all', $findings[0]);
    }

    /** And the other direction, which is what the comparison tool did. */
    #[Test]
    public function a_month_of_nothing_but_carousels_is_refused_too(): void
    {
        $chosen = array_map(
            static fn (PostKind $kind): array => ['kind' => $kind, 'format' => ContentFormat::Carousel],
            array_fill(0, 16, PostKind::Behind),
        );

        $findings = FormatMix::fromConfig()->findings($chosen);

        $this->assertNotSame([], $findings);
        $this->assertStringContainsString('carousels and at most', $findings[0]);
    }

    #[Test]
    public function a_month_with_some_of_each_passes(): void
    {
        $this->assertSame([], FormatMix::fromConfig()->findings($this->month(carousels: 4)));
    }

    /**
     * The denominator, which is the whole reason this is not a shares map.
     *
     * `take` goes to Threads and X and never to Instagram, so it can never be a
     * carousel. Counting it in would ask for carousels the month cannot supply,
     * and a model handed an instruction it cannot satisfy resolves it against
     * whichever half it likes least.
     */
    #[Test]
    public function ideas_that_never_reach_instagram_are_not_counted_as_carousel_capable(): void
    {
        $this->assertSame(0, FormatMix::capable(array_fill(0, 10, PostKind::Take)));
        $this->assertSame(10, FormatMix::capable(array_fill(0, 10, PostKind::Behind)));

        $this->assertFalse(in_array(
            ChannelType::Instagram,
            PostKind::Take->channels(),
            true,
        ));
    }

    /**
     * The instruction and the check have to mean the same thing.
     *
     * They count off different inputs — the instruction off the mix targets
     * because no kind has been chosen yet, the check off the kinds that were —
     * and if the two disagree a model gets refused for producing the month it
     * was asked for.
     */
    #[Test]
    public function the_instruction_and_the_check_agree_on_what_can_carry_a_carousel(): void
    {
        $targets = ContentMix::fromConfig()->targets(20);
        $asked = FormatMix::capableInTargets($targets);

        $kinds = [];

        foreach ($targets as $value => $count) {
            $kinds = [...$kinds, ...array_fill(0, $count, PostKind::from($value))];
        }

        $this->assertSame($asked, FormatMix::capable($kinds));
    }

    /** A carousel-capable month is told a number it can act on, not a share. */
    #[Test]
    public function the_instruction_asks_in_counts_and_names_the_ceiling(): void
    {
        $mix = FormatMix::fromConfig();
        $instruction = $mix->instruction(20, 16);

        $this->assertStringContainsString((string) $mix->carouselTarget(16), $instruction);
        $this->assertStringContainsString('a ceiling, not a target', $instruction);
        $this->assertStringContainsString('Everything else is a single image', $instruction);
        // The failure mode the target is aimed at, named so it is not obeyed
        // by inflating one thought into five slides.
        $this->assertStringContainsString('padding one thought', $instruction);
    }

    /**
     * A month whose ideas nobody could make a carousel from is not nagged.
     *
     * The absence finding is conditional on the target being reachable, so a
     * month of pure `take` — every one of them Threads and X — is a legitimate
     * month rather than one missing something.
     */
    #[Test]
    public function a_month_that_could_not_carry_a_carousel_is_not_asked_for_one(): void
    {
        $chosen = array_map(
            static fn (PostKind $kind): array => ['kind' => $kind, 'format' => ContentFormat::Image],
            array_fill(0, 12, PostKind::Take),
        );

        $this->assertSame([], FormatMix::fromConfig()->findings($chosen));
    }

    /**
     * Sixteen Instagram-bound ideas and four Threads-only ones.
     *
     * @return list<array{kind: PostKind, format: ContentFormat}>
     */
    private function month(int $carousels): array
    {
        $chosen = [];

        for ($i = 0; $i < 16; $i++) {
            $chosen[] = [
                'kind' => PostKind::Behind,
                'format' => $i < $carousels ? ContentFormat::Carousel : ContentFormat::Image,
            ];
        }

        for ($i = 0; $i < 4; $i++) {
            $chosen[] = ['kind' => PostKind::Take, 'format' => ContentFormat::Image];
        }

        return $chosen;
    }
}
