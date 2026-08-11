<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Enums\ChannelType;
use App\Models\BrandBrief;
use App\Pipelines\Steps\SocialDraft\GuardFinding;
use App\Support\Social\ChannelPlaybook;
use App\Support\Social\StudioPostGuard;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StudioPostGuardTest extends TestCase
{
    #[Test]
    public function the_length_it_refuses_is_the_channel_s_own(): void
    {
        $post = [str_repeat('a', 400)];

        $this->assertSame([], $this->codes(ChannelType::Threads, $post));
        $this->assertSame([GuardFinding::LENGTH], $this->codes(ChannelType::X, $post));
    }

    #[Test]
    public function a_chain_has_to_be_argued_for_even_where_threads_are_native(): void
    {
        $playbook = ChannelPlaybook::for(ChannelType::X);
        $chain = ['The claim.', 'The evidence for it.'];

        $this->assertSame(
            [GuardFinding::UNJUSTIFIED_CHAIN],
            array_column(
                array_map(
                    static fn (GuardFinding $f): array => $f->toArray(),
                    StudioPostGuard::check($playbook, $chain),
                ),
                'code',
            ),
        );

        $this->assertSame([], StudioPostGuard::check($playbook, $chain, chainJustified: true));
    }

    #[Test]
    public function instagram_takes_one_caption_and_not_a_chain(): void
    {
        $this->assertSame(
            [GuardFinding::SEGMENT_COUNT, GuardFinding::UNJUSTIFIED_CHAIN],
            $this->codes(ChannelType::Instagram, ['The caption.', 'A second one.']),
        );
    }

    #[Test]
    public function a_topic_the_brief_forbids_is_refused_without_catching_words_that_contain_it(): void
    {
        $brief = new BrandBrief(['forbidden_topics' => ['ICO']]);
        $playbook = ChannelPlaybook::for(ChannelType::Threads);

        $this->assertSame(
            [GuardFinding::FORBIDDEN_TOPIC],
            array_column(
                array_map(
                    static fn (GuardFinding $f): array => $f->toArray(),
                    StudioPostGuard::check($playbook, ['We are not doing an ICO.'], brief: $brief),
                ),
                'code',
            ),
        );

        // The failure mode a substring match has: a guard that refuses every
        // post is a guard somebody switches off.
        $this->assertSame(
            [],
            StudioPostGuard::check($playbook, ['She is a medico by training.'], brief: $brief),
        );
    }

    #[Test]
    public function the_link_policy_is_about_the_shape_around_the_link(): void
    {
        $playbook = ChannelPlaybook::for(ChannelType::Threads);

        $this->assertSame(
            [GuardFinding::BARE_LINK],
            $this->codes(ChannelType::Threads, ['https://persistance.io/blog/one']),
        );

        $this->assertSame(
            [GuardFinding::LINK_POLICY],
            $this->codes(
                ChannelType::Threads,
                ['Two things worth reading this week if you plan content: https://persistance.io/a and https://persistance.io/b'],
            ),
        );

        $this->assertSame(
            [],
            StudioPostGuard::check(
                $playbook,
                ['We wrote up how the planner decides what to drop. Does this match how you do it? https://persistance.io/a'],
            ),
        );
    }

    #[Test]
    public function a_link_that_is_not_a_link_is_refused(): void
    {
        $this->assertSame(
            [GuardFinding::LINK_POLICY],
            $this->codes(
                ChannelType::Threads,
                ['We wrote up how the planner decides what to drop, and what it costs to be wrong.'],
                'javascript:alert(1)',
            ),
        );
    }

    #[Test]
    public function a_conversational_post_is_not_made_to_name_the_project_to_survive(): void
    {
        $playbook = ChannelPlaybook::for(ChannelType::Threads);

        // §4.3's entity resolution is the contour's and not this one's. A real
        // project's vocabulary is long-tail search phrases off its corpus, so
        // applying it here refused every natural post and let through only the
        // ones carrying a line of service copy — the guard selecting for the
        // unframed self-promotion §2 says does not work.
        $this->assertSame(
            [],
            StudioPostGuard::check(
                $playbook,
                ['After a week away, the kitchen is the room I want working first. What do you notice?'],
            ),
        );

        $this->assertSame(
            [],
            $this->codes(
                ChannelType::X,
                ['We stopped cleaning everything at once. The order matters more than the effort.'],
            ),
        );
    }

    #[Test]
    public function a_carousel_panel_is_read_for_what_it_says_but_not_counted_as_a_segment(): void
    {
        $playbook = ChannelPlaybook::for(ChannelType::Instagram);
        $brief = new BrandBrief(['forbidden_topics' => ['refunds']]);

        // A panel is not a segment — counting it as one would refuse every
        // carousel for being a chain.
        $this->assertSame(
            [],
            StudioPostGuard::check($playbook, ['A clean caption.'], brief: $brief, panels: 'Step one\nAll good.'),
        );

        $this->assertSame(
            [GuardFinding::FORBIDDEN_TOPIC],
            array_map(
                static fn (GuardFinding $f): string => $f->code,
                StudioPostGuard::check(
                    $playbook,
                    ['A clean caption.'],
                    brief: $brief,
                    panels: "The catch\nAsk us about refunds if it does not work.",
                ),
            ),
        );
    }

    #[Test]
    public function nothing_at_all_is_its_own_finding(): void
    {
        $this->assertSame([GuardFinding::BLANK], $this->codes(ChannelType::Threads, ['  ']));
    }

    /**
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function codes(ChannelType $channel, array $segments, ?string $link = null): array
    {
        return array_map(
            static fn (GuardFinding $finding): string => $finding->code,
            StudioPostGuard::check(ChannelPlaybook::for($channel), $segments, $link),
        );
    }
}
