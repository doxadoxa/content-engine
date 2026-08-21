<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Enums\ChannelType;
use App\Enums\PostKind;
use App\Support\Social\ChannelPlaybook;
use App\Support\Social\ContentMix;
use App\Support\Social\VisualBriefGuard;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The kind whose subject is a person, and the rules that keep it one.
 *
 * `product/social-personal-register-spec.md` is the argument. In short: the
 * five kinds that existed were all about the service — argue, instruct,
 * demonstrate, show the unglamorous part, sell — so a month of them is a month
 * about cleaning, and the review of the first month said so ("no persons, no
 * soul"). Letting people into the picture rules was necessary and not
 * sufficient: nothing in the calendar *planned* a warm post, because no
 * register asked for one.
 */
final class PersonalRegisterTest extends TestCase
{
    /**
     * The register is only worth having if it cannot be written as a tip.
     *
     * Every other kind converges on advice — "useful" is the safest thing a
     * model can be — so the brief has to refuse it in as many words.
     */
    #[Test]
    public function the_brief_refuses_to_let_it_become_a_how_to(): void
    {
        $brief = PostKind::Life->brief();

        $this->assertStringContainsString('may not teach', $brief);
        $this->assertStringContainsString('No steps, no tips', $brief);
        $this->assertStringContainsString('no ending that turns back to the company', $brief);
    }

    /** And the picture has to be the after, not the work. */
    #[Test]
    public function the_shot_asks_for_the_hours_afterwards(): void
    {
        $shot = PostKind::Life->shot();

        $this->assertStringContainsString('Show a person in the room, using it', $shot);
        $this->assertStringContainsString('no cloth, no gloves, no product', $shot);
    }

    /**
     * Instagram and Threads, not X.
     *
     * The ceiling of two is the enum's own rule. X is the one channel that
     * rewards the compact argument, and a warm observation there is filler.
     */
    #[Test]
    public function it_goes_where_a_room_somebody_recognises_gets_answered(): void
    {
        $this->assertSame(
            [ChannelType::Instagram, ChannelType::Threads],
            PostKind::Life->channels(),
        );

        $this->assertSame('image', PostKind::Life->instagramFormat());
    }

    /**
     * Its angles are the narrative ones, and they exist on the channel.
     *
     * `anglesOn()` intersects with the playbook's own list, so a shape named
     * here that the channel does not know silently narrows the pool.
     */
    #[Test]
    public function every_angle_it_asks_for_is_one_the_channel_has(): void
    {
        foreach ([ChannelType::Instagram, ChannelType::Threads] as $channel) {
            $playbook = ChannelPlaybook::for($channel);
            $asked = PostKind::Life->anglesOn($channel);

            $this->assertNotSame([], $asked);

            foreach ($asked as $angle) {
                $this->assertContains(
                    $angle,
                    $playbook->angles,
                    "{$channel->value} has no “{$angle}” shape, so asking for it narrows the pool to nothing.",
                );
            }

            // Argument shapes belong to `take`; this kind is not arguing.
            $this->assertNotContains('contrarian', $asked);
            $this->assertNotContains('take', $asked);
        }
    }

    /**
     * A month has room for it, and the shares still add up.
     *
     * The share came out of `how_to` rather than off the end, so the total is
     * still a hundred and nothing silently lost its place.
     */
    #[Test]
    public function a_month_plans_some_of_it(): void
    {
        $shares = config('content_studio.mix.shares');

        $this->assertSame(100, array_sum($shares));
        $this->assertSame(15, $shares[PostKind::Life->value]);

        $mix = ContentMix::fromConfig();

        $this->assertSame(3, $mix->targets(20)[PostKind::Life->value]);
        $this->assertStringContainsString('3 life', $mix->instruction(20));
    }

    /**
     * A month with none of it is refused, like the other targeted kinds.
     *
     * This is what makes the register real rather than decorative: without it
     * the model can plan twenty how-tos and satisfy every check that matters.
     */
    #[Test]
    public function a_month_with_nobody_in_it_is_a_finding(): void
    {
        $findings = ContentMix::fromConfig()->findings([
            ...array_fill(0, 10, PostKind::HowTo),
            ...array_fill(0, 10, PostKind::Take),
        ]);

        $this->assertNotSame([], $findings);
        $this->assertStringContainsString('life', implode(' ', $findings));
    }

    /**
     * The guard asks a different question of this kind.
     *
     * Its shot forbids the cloth and the gloves, so every honest brief fails a
     * test that looks for contact or residue. What it must not be is a styled
     * empty room, so the question becomes whether anybody is there.
     */
    #[Test]
    public function a_life_brief_needs_a_person_rather_than_residue(): void
    {
        $peopled = VisualBriefGuard::check([
            'subject' => 'a woman standing at the kitchen window with coffee, early light across the room',
            'action' => 'she watches the street while the kettle finishes behind her',
            'location' => 'a Lisbon apartment kitchen',
        ], PostKind::Life);

        $this->assertSame([], $peopled);

        // The same brief would be refused as any other kind, because there is
        // no work in it anywhere.
        $this->assertCount(1, VisualBriefGuard::check([
            'subject' => 'a woman standing at the kitchen window with coffee, early light across the room',
            'action' => 'she watches the street while the kettle finishes behind her',
        ], PostKind::Take));
    }

    /** An empty room that has been tidied is a catalogue page. */
    #[Test]
    public function a_life_brief_with_nobody_in_it_is_refused(): void
    {
        $complaints = VisualBriefGuard::check([
            'subject' => 'a made bed with the corner of the linen turned back, morning light across it',
            'action' => 'the linen settles',
            'location' => 'a Lisbon apartment bedroom',
        ], PostKind::Life);

        $this->assertCount(1, $complaints);
        $this->assertStringContainsString('nobody in this photograph', $complaints[0]);
    }

    /**
     * And a pair of hands does not count as company.
     *
     * Hands are what the other five kinds show. A month of them with nobody
     * attached is the complaint this whole register answers, so the one kind
     * that exists to fix it may not be satisfied by the thing that caused it.
     */
    #[Test]
    public function hands_are_not_a_person(): void
    {
        $complaints = VisualBriefGuard::check([
            'subject' => 'a pair of hands holding a mug at a kitchen table',
            'action' => 'the hands rest around the mug',
        ], PostKind::Life);

        $this->assertCount(1, $complaints);
        $this->assertStringContainsString('nobody in this photograph', $complaints[0]);
    }
}
