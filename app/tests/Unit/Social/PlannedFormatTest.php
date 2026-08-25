<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\ContentStudio\ContentStudioAssistant;
use App\Enums\ChannelType;
use App\Enums\ContentFormat;
use App\Enums\PostKind;
use App\Models\ContentIdea;
use App\Support\Social\ChannelPlaybook;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The artefact is chosen where the whole month is visible, like the kind and
 * the picture before it.
 *
 * `content_format` existed, was read correctly everywhere downstream, and was
 * written by exactly one thing: a human clicking a chip in the Studio. So every
 * unattended month fell through to {@see PostKind::instagramFormat()}, which
 * gives a carousel to a how-to and a single photograph to everything else —
 * and the counterexample was already in the plan. A `behind` post arguing for a
 * published checklist is a list of eight things; it shipped as a photograph of
 * cloths with the list described in the caption, under a sentence the writer
 * had added to excuse the gap.
 */
final class PlannedFormatTest extends TestCase
{
    /** The planner is asked for one, with the alternation derived from the enum. */
    #[Test]
    public function the_proposal_contract_asks_for_a_format_per_idea(): void
    {
        $instructions = $this->proposalInstructions();

        $this->assertStringContainsString('"format":"'.ContentFormat::alternation().'"', $instructions);
        $this->assertStringContainsString('carousel|image|text', $instructions);
    }

    /**
     * And told what each one is for, from the enum rather than from a copy.
     *
     * This is the fourth list in that prompt whose members are enum cases, and
     * the third went stale the first time a case was added: a whole register
     * reached the planner only as a token inside the mix arithmetic.
     */
    #[Test]
    public function the_planner_is_told_what_each_format_is_for(): void
    {
        $instructions = $this->proposalInstructions();

        foreach (ContentFormat::vocabulary() as $line) {
            $this->assertStringContainsString($line, $instructions);
        }
    }

    /** Said explicitly, because it is the one thing the choice cannot ignore. */
    #[Test]
    public function the_planner_is_told_a_carousel_is_instagram_only(): void
    {
        $this->assertStringContainsString(
            'A carousel is Instagram only',
            $this->proposalInstructions(),
        );
    }

    /**
     * A carousel asked for where one cannot happen becomes an image at parse.
     *
     * {@see ContentFormat::on()} would do it at render time anyway. Doing it
     * here means the stored plan and the Studio's chip name the artefact the
     * post will actually be, instead of one it will never get.
     */
    #[Test]
    public function a_carousel_on_an_idea_that_never_reaches_instagram_becomes_an_image(): void
    {
        $this->assertSame(
            ContentFormat::Image,
            $this->formatFor(PostKind::Take, 'carousel'),
        );

        $this->assertSame(
            ContentFormat::Carousel,
            $this->formatFor(PostKind::Behind, 'carousel'),
        );
    }

    /** An unreadable answer is an absence, which has its own considered default. */
    #[Test]
    public function a_format_nobody_named_stays_null_rather_than_being_guessed(): void
    {
        $this->assertNull($this->formatFor(PostKind::Behind, null));
        $this->assertNull($this->formatFor(PostKind::Behind, 'reel'));
    }

    /** Case and whitespace are the model's business, not ours. */
    #[Test]
    public function the_format_is_read_loosely(): void
    {
        $this->assertSame(ContentFormat::Text, $this->formatFor(PostKind::Take, '  Text '));
    }

    /**
     * Instagram will not publish without media, so a text idea keeps its
     * picture there.
     *
     * `isAvailableOn` used to say text worked everywhere, which was harmless
     * only for as long as nothing ever chose text. The moment the planner
     * could, a `proof` idea marked text would have shipped its Instagram half
     * with nothing in it.
     */
    #[Test]
    public function a_text_idea_still_gets_a_photograph_on_instagram(): void
    {
        $this->assertSame(ContentFormat::Image, ContentFormat::Text->on(ChannelType::Instagram));
        $this->assertSame(ContentFormat::Text, ContentFormat::Text->on(ChannelType::Threads));
    }

    /**
     * A text post is not asked to brief a photograph.
     *
     * The six art-direction fields were written, parsed, guarded and then
     * dropped at the spend gate. The waste is the smaller half: a writer that
     * believes a picture is coming writes a post that leans on one.
     */
    #[Test]
    public function a_text_post_is_told_there_is_no_picture_and_asked_for_no_brief(): void
    {
        $contract = $this->outputContract(ChannelType::Threads, ContentFormat::Text);

        $this->assertStringNotContainsString('"visual"', $contract);
        $this->assertStringContainsString('This post has no picture', $contract);
        $this->assertStringNotContainsString('What this picture has to show', $contract);
    }

    /** And every other post still is. */
    #[Test]
    public function an_image_post_still_gets_the_six_fields(): void
    {
        $contract = $this->outputContract(ChannelType::Threads, ContentFormat::Image);

        $this->assertStringContainsString('"visual"', $contract);
        $this->assertStringNotContainsString('This post has no picture', $contract);
    }

    /** The same text idea, on the channel that cannot honour it. */
    #[Test]
    public function the_instagram_half_of_a_text_idea_is_still_asked_for_a_brief(): void
    {
        $contract = $this->outputContract(ChannelType::Instagram, ContentFormat::Text);

        $this->assertStringContainsString('"visual"', $contract);
        $this->assertStringNotContainsString('This post has no picture', $contract);
    }

    /**
     * The planner's shot is realised, not replaced — including its silences.
     *
     * Measured on the first month planned with shots: 7 of 17 named a hand and
     * 25 of 32 briefs did, so the variety decided across the month was being
     * spent at drafting time. One shot asked for a dining table already reset,
     * with a folded chair and the kitchen soft behind it, and came back a hand
     * wiping crumbs off a worktop — a different photograph, and one that
     * contradicts the word it was given.
     */
    #[Test]
    public function the_writer_is_told_not_to_add_a_person_the_shot_did_not_ask_for(): void
    {
        $contract = $this->outputContract(ChannelType::Threads, ContentFormat::Image);

        $this->assertStringContainsString('sharpen it, do not substitute it', $contract);
        $this->assertStringContainsString('do not add one', $contract);
        // The two substitutions actually observed, named rather than implied.
        $this->assertStringContainsString('rather than moving it to the background', $contract);
        $this->assertStringContainsString('a decision made about the month', $contract);
    }

    /** The rule that used to be the only rule, kept as the fallback and unmoved. */
    #[Test]
    public function an_idea_with_no_chosen_format_still_behaves_as_it_did(): void
    {
        $this->assertSame(ContentFormat::Carousel, ContentFormat::impliedBy(PostKind::HowTo));

        foreach (PostKind::cases() as $kind) {
            if ($kind !== PostKind::HowTo) {
                $this->assertSame(ContentFormat::Image, ContentFormat::impliedBy($kind));
            }
        }
    }

    private function proposalInstructions(): string
    {
        $method = new ReflectionMethod(ContentStudioAssistant::class, 'proposalInstructions');
        $method->setAccessible(true);

        /** @var string $instructions */
        $instructions = $method->invoke(app(ContentStudioAssistant::class));

        return $instructions;
    }

    private function formatFor(PostKind $kind, mixed $requested): ?ContentFormat
    {
        $method = new ReflectionMethod(ContentStudioAssistant::class, 'formatFor');
        $method->setAccessible(true);

        /** @var ContentFormat|null $format */
        $format = $method->invoke(app(ContentStudioAssistant::class), $kind, $requested);

        return $format;
    }

    private function outputContract(ChannelType $channel, ContentFormat $format): string
    {
        $idea = new ContentIdea;
        $idea->kind = PostKind::Take;
        $idea->content_format = $format;
        $idea->shot = 'hands folding a cloth over the edge of a sink';

        $method = new ReflectionMethod(ContentStudioAssistant::class, 'outputContract');
        $method->setAccessible(true);

        /** @var string $contract */
        $contract = $method->invoke(
            app(ContentStudioAssistant::class),
            ChannelPlaybook::for($channel),
            $idea,
        );

        return $contract;
    }
}
