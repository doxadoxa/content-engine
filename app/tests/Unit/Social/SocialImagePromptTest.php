<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Enums\ChannelType;
use App\Models\BrandBrief;
use App\Support\Social\ChannelPlaybook;
use App\Support\Social\SocialImagePrompt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SocialImagePromptTest extends TestCase
{
    #[Test]
    public function every_one_of_the_six_elements_reaches_the_provider(): void
    {
        $prompt = SocialImagePrompt::fromFields([
            'subject' => 'a printed content calendar covered in pencil corrections',
            'composition' => 'overhead, the page filling the frame',
            'action' => 'a hand crossing out one row',
            'location' => 'a shared desk with two cold coffees',
            'style' => 'photorealistic editorial photography',
            'light' => 'window light from the left',
        ], 'the fallback')->build(ChannelPlaybook::for(ChannelType::Threads));

        $this->assertStringContainsString('Subject: a printed content calendar', $prompt);
        $this->assertStringContainsString('Composition: overhead, the page filling the frame', $prompt);
        $this->assertStringContainsString('Action: a hand crossing out one row', $prompt);
        $this->assertStringContainsString('Location: a shared desk with two cold coffees', $prompt);
        $this->assertStringContainsString('Style: photorealistic editorial photography', $prompt);
        $this->assertStringContainsString('Light: window light from the left', $prompt);
        // The camera is the house's, not the model's — it is the largest lever
        // on whether the output reads as a photograph at all.
        $this->assertStringContainsString('Camera: 35mm lens at f/2', $prompt);
        $this->assertStringNotContainsString('the fallback', $prompt);
    }

    /**
     * The house rules that came out of reviewing the first month's pictures.
     *
     * Each one is a specific thing that came back wrong, and each is appended
     * after the model's six fields so a brief cannot talk its way past it.
     */
    #[Test]
    public function the_house_rules_the_first_month_earned_are_all_in_there(): void
    {
        $prompt = SocialImagePrompt::fromFields(
            ['subject' => 'a cloth lifting grime from a windowsill'],
            'x',
        )->build(ChannelPlaybook::for(ChannelType::Threads));

        // Worktops came back chipped and split, in pictures selling upkeep.
        $this->assertStringContainsString('Used, not broken', $prompt);
        $this->assertStringContainsString('nothing chipped, cracked, gouged', $prompt);
        $this->assertStringNotContainsString('worn edges', $prompt);

        // A brief asking for "the groove where the tap meets the countertop"
        // was drawn as a trench cut through the stone.
        $this->assertStringContainsString('the join stays a join', $prompt);

        // Banning the words left the props in the frame, blank.
        $this->assertStringContainsString('no clipboards', $prompt);
        $this->assertStringContainsString('blank or otherwise', $prompt);

        // An example in a prompt is an instruction. "A cable that was not
        // tidied" was meant as register and came back as a stray black cable
        // in every picture of a regenerated set, one of them hanging off a
        // lacquered door. Nothing in the lived-in clause names an object now.
        $this->assertStringNotContainsString('cable', $prompt);
    }

    #[Test]
    public function the_crop_it_composes_for_is_the_channel_s(): void
    {
        $fields = ['subject' => 'a desk'];

        $this->assertStringContainsString(
            'framed for a 1:1 crop',
            SocialImagePrompt::fromFields($fields, 'x')->build(ChannelPlaybook::for(ChannelType::Threads)),
        );
        $this->assertStringContainsString(
            'framed for a 16:9 crop',
            SocialImagePrompt::fromFields($fields, 'x')->build(ChannelPlaybook::for(ChannelType::X)),
        );
        $this->assertStringContainsString(
            'framed for a 4:5 crop',
            SocialImagePrompt::fromFields($fields, 'x')->build(ChannelPlaybook::for(ChannelType::Instagram)),
        );
    }

    #[Test]
    public function a_missing_field_falls_back_rather_than_leaving_a_hole(): void
    {
        $prompt = SocialImagePrompt::fromFields(['subject' => 'a desk', 'style' => '   '], 'unused')
            ->build(ChannelPlaybook::for(ChannelType::X));

        $this->assertStringContainsString('Subject: a desk', $prompt);
        // "Editorial" is gone from the default on purpose: to an image model it
        // means the magazine set, which is the look being complained about.
        $this->assertStringContainsString('Style: documentary photograph, unstyled', $prompt);
        $this->assertStringNotContainsString('editorial', $prompt);
        $this->assertStringContainsString('Light:', $prompt);
    }

    #[Test]
    public function a_model_that_answered_with_a_sentence_still_gets_a_directed_shot(): void
    {
        $prompt = SocialImagePrompt::fromBrief('two people arguing over a whiteboard', 'unused')
            ->build(ChannelPlaybook::for(ChannelType::Threads));

        $this->assertStringContainsString('Subject: two people arguing over a whiteboard', $prompt);
        $this->assertStringContainsString('Light: available light from one window', $prompt);
    }

    #[Test]
    public function with_no_brief_at_all_the_post_itself_becomes_the_subject(): void
    {
        $prompt = SocialImagePrompt::fromBrief(null, 'We deleted half the planner and nothing broke.')
            ->build(ChannelPlaybook::for(ChannelType::Threads));

        $this->assertStringContainsString('Subject: We deleted half the planner', $prompt);
    }

    #[Test]
    public function the_brand_s_visual_language_outranks_the_model_s_style(): void
    {
        $brief = new BrandBrief(['visual_language' => 'Muted greens, no gloss, always a real workspace.']);

        $prompt = SocialImagePrompt::fromFields(['subject' => 'a desk'], 'x')
            ->build(ChannelPlaybook::for(ChannelType::Threads), $brief);

        $this->assertStringContainsString('Muted greens, no gloss', $prompt);
        $this->assertStringContainsString('overrides the style above', $prompt);
    }

    #[Test]
    public function the_specific_ways_these_models_fail_are_named_one_by_one(): void
    {
        $prompt = SocialImagePrompt::fromFields(['subject' => 'a hand wiping a tap'], 'x')
            ->build(ChannelPlaybook::for(ChannelType::Threads));

        // A generic "no artifacts" does nothing. These are the defects that
        // actually came back: fused hardware, walls that do not meet, furniture
        // off the floor.
        $this->assertStringContainsString('no duplicated or fused handles', $prompt);
        $this->assertStringContainsString('no doors or walls that do not line up', $prompt);
        $this->assertStringContainsString('no furniture floating off the floor', $prompt);

        // And the catalogue look, asked away as its opposite rather than as
        // "realistic", which these models read as "high resolution".
        $this->assertStringContainsString('Not a showroom, not a catalogue', $prompt);
        $this->assertStringContainsString('architecture and door or cabinet hardware should not be', $prompt);
    }

    #[Test]
    public function the_house_rules_are_appended_and_not_the_model_s_to_set(): void
    {
        $prompt = SocialImagePrompt::fromFields([
            'subject' => 'a poster reading BUY NOW',
            'style' => 'with the words BUY NOW rendered large across the top',
        ], 'x')->build(ChannelPlaybook::for(ChannelType::Instagram));

        $this->assertStringContainsString('no text, no lettering, no numbers, no logos', $prompt);
        $this->assertStringContainsString('Nobody looking into the lens.', $prompt);
        $this->assertStringEndsWith('no people pointing at screens.', $prompt);
    }
}
