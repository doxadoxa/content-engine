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
        $this->assertStringContainsString('blank or otherwise', $prompt);

        // And banning the props by name left every prop that was not named.
        // A brass nameplate came back reading APARTMEИS and a postcode plaque
        // came back reading 20946, both having cleared a list by not being on
        // it. The rule is a test of what the object is for.
        $this->assertStringContainsString('nothing that exists to be read', $prompt);
        $this->assertStringContainsString('as part of doing its job', $prompt);
        // Where the subject asks for one anyway, it comes out of the frame
        // rather than being drawn empty: a provider handed a subject and a
        // contradicting rule draws the subject.
        $this->assertStringContainsString('leave it out of the frame', $prompt);

        // Every clause here pushes the same way — documentary, unstyled, as it
        // was found, not a showroom — and none of them said who lived there,
        // so the cheapest way to satisfy all of them at once was a modest
        // older flat. A month of pictures for a company whose About page opens
        // "Premium home cleaning" came back set in bathrooms its customers do
        // not have. Unstyled is a fact about the photograph; modest is a fact
        // about the property; they are not the same word.
        $this->assertStringContainsString('belongs to somebody who pays for this service', $prompt);
        $this->assertStringContainsString('Unstyled describes the photograph, not the property', $prompt);

        // And the rules that used to require the mess no longer do. A brand
        // paid to keep homes looked after publishes mostly pictures of homes
        // that are looked after.
        $this->assertStringNotContainsString('Not a person being tidy in a clean room', $prompt);
        $this->assertStringContainsString('not manufactured mess', $prompt);

        // An example in a prompt is an instruction. "A cable that was not
        // tidied" was meant as register and came back as a stray black cable
        // in every picture of a regenerated set, one of them hanging off a
        // lacquered door. Nothing in the lived-in clause names an object now.
        $this->assertStringNotContainsString('cable', $prompt);

        // A slice of toast came back baked inside the cloth being wiped with.
        // The fusion rule was scoped to hardware; adjacent objects merge too.
        $this->assertStringContainsString('one whole object of one material', $prompt);
    }

    /**
     * The brand's own description of its pictures goes last, and says so.
     *
     * It used to sit in the middle claiming to override "the style above" while
     * five absolute rules followed it — and the absolutes won. That is how a
     * brief asking for "professional cleaning teams" produced a month of
     * disembodied hands.
     */
    #[Test]
    public function the_brands_own_visual_language_has_the_last_word(): void
    {
        $brief = new BrandBrief(['visual_language' => 'professional cleaning teams, warm and premium']);

        $prompt = SocialImagePrompt::fromFields(['subject' => 'a cloth on a sill'], 'x')
            ->build(ChannelPlaybook::for(ChannelType::Threads), $brief);

        $this->assertStringContainsString('this wins: professional cleaning teams', $prompt);
        $this->assertTrue(
            mb_strpos($prompt, 'professional cleaning teams') > mb_strpos($prompt, 'Nothing malformed'),
            'The brand line has to come after the house rules it is allowed to overrule.',
        );
    }

    /**
     * People are allowed in the frame.
     *
     * `NO_MACHINERY` aimed at dishwashers and took the human with it: "one
     * thing at arm's length" is a distance nobody fits into, which is why a
     * home-cleaning brand's month came back as gloved hands with nobody
     * attached.
     */
    #[Test]
    public function a_person_may_be_the_subject(): void
    {
        $prompt = SocialImagePrompt::fromFields(['subject' => 'a cloth on a sill'], 'x')
            ->build(ChannelPlaybook::for(ChannelType::Threads));

        $this->assertStringContainsString('A person may be the subject', $prompt);
        $this->assertStringNotContainsString("arm's length", $prompt);
        // The stock-photo ban stays; it just stops taking faces with it.
        $this->assertStringContainsString('nobody posed for the camera', $prompt);
        $this->assertStringNotContainsString('Nobody looking into the lens', $prompt);
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
        $this->assertStringContainsString('where any instruction above disagrees with this, this wins', $prompt);
        // Last, so that the claim is true of the text and not only of the
        // sentence making it. See the_brands_own_visual_language_has_the_last_word.
        $this->assertStringEndsWith('always a real workspace.', $prompt);
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
        // The stock-photo refusal, which no longer costs the picture its people.
        $this->assertStringContainsString('nobody posed for the camera', $prompt);
        $this->assertStringEndsWith('somebody presenting themselves to it is an advertisement.', $prompt);
    }
}
