<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\ContentStudio\ContentStudioAssistant;
use App\Enums\ChannelType;
use App\Enums\PostKind;
use App\Support\Social\ChannelPlaybook;
use App\Support\Social\ContentMix;
use App\Support\Social\VisualBriefGuard;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
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
     * The planner is told the kind exists, which is not automatic.
     *
     * Adding the case and giving it a share is not enough on its own: the
     * proposal instructions listed the kinds as five hardcoded bullets, so the
     * planner met `life` only as a token in the mix arithmetic — "about 3
     * life", with nothing saying what one is — and planned a month of the five
     * it had been told about instead, twice, through the correction loop. The
     * list is derived from the enum now, and this is the test that says so.
     */
    #[Test]
    public function the_planners_vocabulary_covers_every_kind_there_is(): void
    {
        $vocabulary = implode("\n", PostKind::vocabulary());
        $routing = PostKind::routing();

        $this->assertCount(count(PostKind::cases()), PostKind::vocabulary());

        foreach (PostKind::cases() as $kind) {
            $this->assertStringContainsString("- {$kind->value}: ", $vocabulary);
            $this->assertStringContainsString("{$kind->value} → ", $routing);
        }

        // The one whose absence caused this, and the sentence it needed most.
        $this->assertStringContainsString('somebody at home', $vocabulary);
        $this->assertStringContainsString('life → instagram, threads', $routing);
    }

    /**
     * The output contract has to allow what the mix asks for.
     *
     * The third place the kinds were typed out, and the one that would have
     * bitten hardest: a model handed a mix asking for three `life` ideas and an
     * output contract whose `kind` alternation lists five values without it
     * resolves the contradiction in favour of the contract. It happened to
     * comply anyway on the run that added the case, which is worse than failing
     * — the drift was invisible.
     */
    #[Test]
    public function the_output_contract_allows_every_kind_the_mix_asks_for(): void
    {
        $method = new ReflectionMethod(ContentStudioAssistant::class, 'proposalInstructions');
        $method->setAccessible(true);

        /** @var string $instructions */
        $instructions = $method->invoke(app(ContentStudioAssistant::class));

        $this->assertStringContainsString(
            '"kind":"'.implode('|', PostKind::values()).'"',
            $instructions,
        );
    }

    /**
     * A shape outranks a register, so the shape may not contradict it.
     *
     * Instagram's `story` ends in "what it taught us, then one thing to do",
     * which is the right ending for four of the six kinds and the exact thing
     * `life` may not do. `angles()` documents that the concrete shape wins, so
     * the pool was spending calls writing how-tos with a person in them.
     */
    #[Test]
    public function the_instagram_shape_it_uses_does_not_teach(): void
    {
        $playbook = ChannelPlaybook::for(ChannelType::Instagram);

        $this->assertNotContains('story', PostKind::Life->anglesOn(ChannelType::Instagram));
        $this->assertContains('moment', PostKind::Life->anglesOn(ChannelType::Instagram));

        $moment = $playbook->shape('moment');

        $this->assertStringContainsString('No lesson, no takeaway, no steps', $moment);
        // And the shape it replaced still teaches, which is why it was replaced
        // rather than edited — four kinds want that ending.
        $this->assertStringContainsString('what it taught', $playbook->shape('story'));
    }

    /**
     * A room named after a person is not a person.
     *
     * The people list was stem-matched, which allows four trailing letters so
     * that a verb can conjugate. On nouns that is a hole: `guest` found
     * "guestroom" and `man` found "mantel", so an empty guest room with a clock
     * on the mantelpiece counted as two people and passed the one check written
     * to refuse exactly that.
     */
    #[Test]
    public function a_guestroom_is_not_a_guest(): void
    {
        foreach ([
            'an empty guestroom with the bed made and the curtains open',
            'a clock on the mantel above a swept fireplace',
            'a childproof latch on a tidy cupboard door',
        ] as $subject) {
            $complaints = VisualBriefGuard::check(['subject' => $subject], PostKind::Life);

            $this->assertCount(1, $complaints, "“{$subject}” should not read as company.");
            $this->assertStringContainsString('nobody in this photograph', $complaints[0]);
        }

        // The words themselves still work when they mean people.
        $this->assertSame([], VisualBriefGuard::check(
            ['subject' => 'a guest taking off her coat in the hallway while the family finishes dinner'],
            PostKind::Life,
        ));
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
