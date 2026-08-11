<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Enums\ChannelType;
use App\Support\Social\ChannelPlaybook;
use App\Support\Social\ChannelPostScore;
use App\Support\Social\PostFormat;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ChannelPostScoreTest extends TestCase
{
    #[Test]
    public function on_threads_it_agrees_with_the_scorer_the_contour_already_uses(): void
    {
        $playbook = ChannelPlaybook::for(ChannelType::Threads);

        $posts = [
            ['We rewrote the planner and the queue got 40% shorter. What did you cut to get there?'],
            ['A flat statement with nothing to recommend it.'],
            [str_repeat('An unbroken paragraph that keeps going. ', 12)],
            ['Worth reading: https://persistance.io/blog/one'],
        ];

        foreach ($posts as $segments) {
            $this->assertSame(
                PostFormat::score($segments),
                ChannelPostScore::score($playbook, $segments),
                'The two scorers must not drift: '.$segments[0],
            );
        }
    }

    #[Test]
    public function a_chain_costs_less_when_somebody_argued_for_it(): void
    {
        $playbook = ChannelPlaybook::for(ChannelType::X);
        $segments = ['The first half of the argument.', 'The half that did not fit.'];

        $unjustified = ChannelPostScore::score($playbook, $segments);
        $justified = ChannelPostScore::score($playbook, $segments, chainJustified: true);

        $this->assertSame(
            PostFormat::CHAIN_UNJUSTIFIED - PostFormat::CHAIN_JUSTIFIED,
            $justified - $unjustified,
        );
        $this->assertLessThan(
            ChannelPostScore::score($playbook, ['One post that says the whole thing.']),
            $justified,
            'A justified chain is still worse than not needing one.',
        );
    }

    #[Test]
    public function hashtags_cost_what_the_channel_says_they_cost(): void
    {
        $clean = 'We shipped the queue rewrite this week.';
        $tagged = $clean.' #content #marketing #ai';

        $threads = ChannelPlaybook::for(ChannelType::Threads);
        $instagram = ChannelPlaybook::for(ChannelType::Instagram);

        // Zero is the ceiling on Threads, so all three are over it.
        $this->assertSame(
            3 * ChannelPostScore::HASHTAG_OVER_CEILING,
            ChannelPostScore::score($threads, [$clean]) - ChannelPostScore::score($threads, [$tagged]),
        );

        // Instagram is the one place they still do something.
        $this->assertSame(
            ChannelPostScore::score($instagram, [$clean]),
            ChannelPostScore::score($instagram, [$tagged]),
        );
    }

    #[Test]
    public function a_stuffed_caption_cannot_drive_the_score_below_zero(): void
    {
        $playbook = ChannelPlaybook::for(ChannelType::Threads);
        $stuffed = 'A post.'.str_repeat(' #tag', 40);

        $this->assertSame(0, ChannelPostScore::score($playbook, [$stuffed]));
    }

    #[Test]
    public function assistant_filler_is_penalised_and_only_where_it_appears(): void
    {
        $playbook = ChannelPlaybook::for(ChannelType::X);
        $plain = 'We cut the planner down to one queue.';
        $filler = 'In today\'s fast-paced world, we cut the planner down to one queue.';

        $this->assertTrue(ChannelPostScore::hasFiller($filler));
        $this->assertFalse(ChannelPostScore::hasFiller($plain));
        $this->assertSame(
            ChannelPostScore::FILLER,
            ChannelPostScore::score($playbook, [$plain]) - ChannelPostScore::score($playbook, [$filler]),
        );
    }

    #[Test]
    public function a_caption_is_rewarded_for_earning_the_more_tap(): void
    {
        $playbook = ChannelPlaybook::for(ChannelType::Instagram);
        $body = str_repeat('The rest of the caption, one thought per line. ', 6);

        $hooked = "We deleted half the planner.\n\n".$body;
        $buried = 'We spent a long time considering whether the planner needed to keep doing all of the '
            .'things it had accumulated over the previous two quarters and eventually. '.$body;

        $this->assertTrue(ChannelPostScore::hooksBeforeTheFold($hooked));
        $this->assertFalse(ChannelPostScore::hooksBeforeTheFold($buried));
        $this->assertSame(
            ChannelPostScore::HOOK,
            ChannelPostScore::score($playbook, [$hooked]) - ChannelPostScore::score($playbook, [$buried]),
        );
    }

    #[Test]
    public function what_counts_as_a_lecture_moves_with_the_channel(): void
    {
        $post = str_repeat('a', 350);

        // Comfortably inside the 400 Threads allows, well past the 224 that X
        // does. The old code applied one number to every channel.
        $this->assertSame(
            PostFormat::BASE,
            ChannelPostScore::score(ChannelPlaybook::for(ChannelType::Threads), [$post]),
        );
        $this->assertSame(
            PostFormat::BASE - PostFormat::LECTURE,
            ChannelPostScore::score(ChannelPlaybook::for(ChannelType::X), [$post]),
        );
    }

    #[Test]
    public function an_empty_candidate_is_worth_nothing(): void
    {
        $this->assertSame(0, ChannelPostScore::score(ChannelPlaybook::for(ChannelType::X), []));
        $this->assertSame(0, ChannelPostScore::score(ChannelPlaybook::for(ChannelType::X), ['   ']));
    }
}
