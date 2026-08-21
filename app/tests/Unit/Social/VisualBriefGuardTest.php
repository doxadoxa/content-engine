<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Enums\PostKind;
use App\Support\Social\VisualBriefGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The guard, checked against the briefs that caused it.
 *
 * Every fixture below is a real `visual` object taken off a draft in the first
 * month's output, and each is the picture somebody looked at and asked what it
 * was for. Written from the real ones rather than from invented ones because
 * the risk in a word-list guard is not the case it was written for — it is the
 * good brief it refuses, and only the real corpus shows that.
 */
final class VisualBriefGuardTest extends TestCase
{
    /**
     * The mug. Nothing is happening: a hand puts down a cup on a clean counter,
     * which is a competent photograph of no work at all.
     */
    #[Test]
    public function it_refuses_a_brief_with_no_work_in_it(): void
    {
        $complaints = VisualBriefGuard::check([
            'subject' => 'A pair of hands placing a frequently used ceramic mug onto a clean kitchen '
                .'counter beside a small bowl of fruit and a set of keys',
            'composition' => 'Close crop at counter height, focused on the hands, mug and a small '
                .'cleared section of worktop; the background falls softly out of focus',
            'action' => 'The hands set down the mug after use, leaving the space visibly clean but '
                .'naturally in use',
            'location' => 'A Lisbon apartment kitchen',
        ], PostKind::Take);

        $this->assertCount(1, $complaints);
        $this->assertStringContainsString('Nothing is happening', $complaints[0]);
    }

    /** The clipboard, briefed with its own text disclaimed away. */
    #[Test]
    public function it_refuses_a_text_prop_even_with_the_text_disclaimed(): void
    {
        $complaints = VisualBriefGuard::check([
            'subject' => 'A cleaner’s gloved hand holding a small detailing brush beside a white window '
                .'track, with a folded microfibre cloth and a checklist-style clipboard partly visible',
            'action' => 'The brush is lifting dust from the narrow channel while the other hand steadies '
                .'the cloth',
            'composition' => 'the clipboard is soft in the background with no legible writing',
        ], PostKind::Take);

        // The work is real — a brush lifting dust — so only the prop is wrong.
        $this->assertCount(1, $complaints);
        $this->assertStringContainsString('clipboard', $complaints[0]);
        $this->assertStringContainsString('checklist', $complaints[0]);
    }

    /** The tablet, which also asked for the interface in as many words. */
    #[Test]
    public function it_refuses_a_screen_briefed_as_an_unlabelled_interface(): void
    {
        $complaints = VisualBriefGuard::check([
            'subject' => 'A close-up of a professional cleaner’s hands holding a tablet beside a folded '
                .'microfibre cloth; the tablet shows an unlabeled checklist-style interface with simple '
                .'lines and check marks, no readable text.',
            'action' => 'One hand taps a checklist item on the tablet while the other steadies the cloth',
            'location' => 'A lived-in Lisbon apartment kitchen, with subtle signs of use such as a water '
                .'glass and a few crumbs near the worktop edge.',
        ], PostKind::Behind);

        $this->assertCount(1, $complaints);
        $this->assertStringContainsString('tablet', $complaints[0]);
    }

    /** The door: a `proof` shot with nothing proven, held one moment too early. */
    #[Test]
    public function it_refuses_a_frame_held_before_the_work_starts(): void
    {
        $complaints = VisualBriefGuard::check([
            'subject' => 'A close view of a white lacquered interior door and its brushed-metal handle',
            'action' => 'A hand holds a soft white cloth near the door surface, paused before wiping',
            'location' => 'A lived-in Lisbon apartment hallway',
        ], PostKind::Proof);

        $this->assertCount(1, $complaints);
        $this->assertStringContainsString('moment before the work', $complaints[0]);
    }

    /** The keys: all three faults in one brief. */
    #[Test]
    public function it_reports_every_fault_rather_than_the_first(): void
    {
        $complaints = VisualBriefGuard::check([
            'subject' => 'A hand placing a house key beside a small open notebook and a phone on a light '
                .'stone kitchen counter',
            'action' => 'The hand is setting down the key as if preparing practical details before '
                .'making an enquiry',
            'location' => 'A lived-in Lisbon apartment kitchen near a window',
        ], PostKind::HowTo);

        $this->assertCount(3, $complaints);
    }

    /** The tap: the one in the set whose brief was right. */
    #[Test]
    public function it_passes_a_brief_that_shows_the_work(): void
    {
        $complaints = VisualBriefGuard::check([
            'subject' => 'A gloved hand holding a narrow detailing brush beside the edge of a kitchen tap',
            'composition' => 'Close crop from above, focused on the brush tip and the small groove where '
                .'the tap meets the countertop',
            'action' => 'The hand is brushing accumulated residue from the hard-to-reach edge after the '
                .'main surface has been wiped',
            'location' => 'A lived-in Lisbon apartment kitchen counter with a pale stone surface',
        ], PostKind::HowTo);

        $this->assertSame([], $complaints);
    }

    /**
     * An `offer` shows the finished state, so it has no residue and nobody
     * scrubbing. Applying the emptiness rule to it would refuse the one kind
     * whose picture is supposed to be clean.
     */
    #[Test]
    public function it_allows_an_offer_to_show_a_finished_room(): void
    {
        $brief = [
            'subject' => 'A made bed with the corner of the linen turned back',
            'action' => 'The linen settles after being straightened',
            'location' => 'A Lisbon apartment bedroom',
        ];

        $this->assertSame([], VisualBriefGuard::check($brief, PostKind::Offer));
        $this->assertCount(1, VisualBriefGuard::check($brief, PostKind::Take));
    }

    /**
     * The word a cleaning brand is entitled to use.
     *
     * A dishwasher tablet is not a screen, and a guard that cannot tell the
     * difference would refuse the most ordinary product shot this brand has.
     */
    #[Test]
    public function it_knows_a_dishwasher_tablet_from_a_screen(): void
    {
        $this->assertSame([], VisualBriefGuard::check([
            'subject' => 'A dishwasher tablet held over the open detergent drawer',
            'action' => 'The tablet drops into the drawer, powder residue around the rim',
        ], PostKind::HowTo));

        $this->assertCount(1, VisualBriefGuard::check([
            'subject' => 'A tablet propped against the kettle',
            'action' => 'A hand wipes the worktop beside it',
        ], PostKind::HowTo));
    }

    /**
     * Words that merely contain a banned one are not the banned one. `\b` would
     * be fine for these; `\p{L}` is what the brief's Portuguese and Cyrillic
     * neighbours need, and it has to behave the same here.
     */
    #[Test]
    #[DataProvider('innocentWords')]
    public function it_does_not_find_a_prop_inside_a_longer_word(string $subject): void
    {
        $this->assertSame([], VisualBriefGuard::check([
            'subject' => $subject,
            'action' => 'A cloth lifts the grime from the surface',
        ], PostKind::HowTo));
    }

    /** @return iterable<string, array{string}> */
    public static function innocentWords(): iterable
    {
        yield 'perform' => ['Hands performing the last pass of the day'];
        yield 'uniform' => ['A cleaner’s uniform sleeve pushed back at the wrist'];
        yield 'headphones' => ['Headphones left on the arm of a chair'];
        yield 'sunscreen' => ['A bottle of sunscreen knocked over on the shelf'];
        yield 'unlabelled' => ['An unlabelled spray bottle beside the sink'];
        yield 'apartment' => ['A Lisbon apartment hallway'];
    }

    /**
     * A `proof` shot may be the after, and an after is clean.
     *
     * The emptiness rule looks for dirt or contact, and this brief has neither
     * — it is a surface with nothing on it but the light, which is the whole
     * point of the picture. Refusing it would mean the one kind whose shot is
     * defined as "show the difference" could not show the better half of it.
     */
    #[Test]
    public function it_allows_a_proof_to_be_the_state_afterwards(): void
    {
        $this->assertSame([], VisualBriefGuard::check([
            'subject' => 'the corner of a worktop where the light now reflects evenly off the stone',
            'action' => 'the surface holds the reflection unbroken to the edge',
            'location' => 'a Lisbon apartment kitchen',
        ], PostKind::Proof));
    }

    /**
     * Description is not anticipation.
     *
     * "As though nobody had touched it" is evidence — the exact evidence the
     * emptiness rule asks for — and an earlier version of the phrase list
     * refused it for containing a word about time.
     */
    #[Test]
    public function it_does_not_mistake_a_simile_for_a_pause(): void
    {
        $this->assertSame([], VisualBriefGuard::check([
            'subject' => 'a windowsill with dust banked in the corner as though nobody had touched it '
                .'in weeks',
            'action' => 'a cloth drags the first line through it',
        ], PostKind::Behind));
    }

    /** No brief at all is not this guard's complaint to make. */
    #[Test]
    public function it_says_nothing_about_an_empty_brief(): void
    {
        $this->assertSame([], VisualBriefGuard::check([], PostKind::Take));
        $this->assertSame([], VisualBriefGuard::check(['subject' => '   '], PostKind::Take));
    }
}
