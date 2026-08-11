<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Enums\ChannelType;
use App\Enums\PostKind;
use App\Support\Social\ContentMix;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContentMixTest extends TestCase
{
    #[Test]
    public function a_month_of_nothing_but_tips_is_refused_with_a_reason(): void
    {
        $findings = ContentMix::fromConfig()->findings(
            array_fill(0, 20, PostKind::HowTo),
        );

        $this->assertNotSame([], $findings);
        $this->assertStringContainsString('no take', $findings[0]);
    }

    #[Test]
    public function a_balanced_month_passes(): void
    {
        $month = [
            ...array_fill(0, 6, PostKind::HowTo),
            ...array_fill(0, 5, PostKind::Take),
            ...array_fill(0, 4, PostKind::Proof),
            ...array_fill(0, 3, PostKind::Behind),
            ...array_fill(0, 2, PostKind::Offer),
        ];

        $this->assertSame([], ContentMix::fromConfig()->findings($month));
    }

    #[Test]
    public function selling_past_the_ceiling_is_refused_but_selling_nothing_is_not(): void
    {
        $mix = ContentMix::fromConfig();

        $base = [
            ...array_fill(0, 8, PostKind::HowTo),
            ...array_fill(0, 6, PostKind::Take),
            ...array_fill(0, 4, PostKind::Proof),
            ...array_fill(0, 2, PostKind::Behind),
        ];

        // Twenty ideas, ten percent, so two. A month with none of them is a
        // good month — «недобор допустим, перебор — нет».
        $this->assertSame([], $mix->findings($base));

        $over = [...array_slice($base, 0, 17), PostKind::Offer, PostKind::Offer, PostKind::Offer];
        $findings = $mix->findings($over);

        $this->assertNotSame([], $findings);
        $this->assertStringContainsString('3 offer posts', $findings[0]);
        $this->assertStringContainsString('at most 2', $findings[0]);
    }

    #[Test]
    public function the_instruction_the_model_gets_is_the_arithmetic_it_is_held_to(): void
    {
        $mix = ContentMix::fromConfig();
        $instruction = $mix->instruction(20);

        $this->assertSame(2, $mix->offerLimit(20));
        $this->assertStringContainsString('about 6 how_to', $instruction);
        $this->assertStringContainsString('about 5 take', $instruction);
        $this->assertStringContainsString('At most 2 of them may be an offer post', $instruction);
        $this->assertStringNotContainsString('%', $instruction);
    }

    #[Test]
    public function an_empty_month_has_nothing_to_say_about_it(): void
    {
        $this->assertSame([], ContentMix::fromConfig()->findings([]));
    }

    #[Test]
    public function no_kind_reaches_every_channel(): void
    {
        foreach (PostKind::cases() as $kind) {
            $this->assertLessThanOrEqual(
                2,
                count($kind->channels()),
                "{$kind->value} goes to more than two channels, which is cross-posting by design.",
            );
            $this->assertNotSame([], $kind->channels());
        }
    }

    #[Test]
    public function teaching_is_the_carousel_and_nothing_else_is(): void
    {
        $this->assertSame('carousel', PostKind::HowTo->instagramFormat());

        foreach (PostKind::cases() as $kind) {
            if ($kind !== PostKind::HowTo) {
                $this->assertSame('image', $kind->instagramFormat());
            }
        }
    }

    #[Test]
    public function an_offer_never_lands_on_the_conversational_channels(): void
    {
        $this->assertSame([ChannelType::Instagram], PostKind::Offer->channels());
    }

    #[Test]
    public function an_unreadable_kind_falls_back_rather_than_failing(): void
    {
        $this->assertSame(PostKind::Take, PostKind::tryFromLoose('  TAKE '));
        $this->assertNull(PostKind::tryFromLoose('editorial'));
        $this->assertNull(PostKind::tryFromLoose(42));
        $this->assertSame(PostKind::HowTo, PostKind::fallback());
    }
}
