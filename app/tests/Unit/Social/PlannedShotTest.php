<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\ContentStudio\ContentStudioAssistant;
use App\Models\BrandBrief;
use App\Support\Social\SocialImagePrompt;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The month's pictures are decided while the month is still one thing.
 *
 * Variety across a set is a property of the set — the argument `ContentMix`
 * already makes about kinds. The pictures had the same problem and a worse
 * remedy: the drafting step read what was already stored on the plan and was
 * told to differ from it, which works only if drafts are written one after
 * another. They are not; a month fans out one run per idea, so eighteen runs
 * start at once and each reads an almost empty plan.
 *
 * Measured on a real month before this: 33 of 40 briefs described a hand, 21 a
 * gloved hand, 14 a brush.
 */
final class PlannedShotTest extends TestCase
{
    /** The planner is asked for one, and told what makes it useful. */
    #[Test]
    public function the_proposal_contract_asks_for_a_shot_per_idea(): void
    {
        $instructions = $this->proposalInstructions();

        $this->assertStringContainsString('"shot":"..."', $instructions);
        $this->assertStringContainsString('Across the month these have to differ', $instructions);
        // Wording is the easy way to satisfy "differ" and the useless one.
        $this->assertStringContainsString('differ by more than wording', $instructions);
    }

    /**
     * And told what a shot is, because the failure it replaces is abstraction.
     *
     * A planner asked for a photograph without this answers "professionalism".
     */
    #[Test]
    public function it_says_a_shot_is_something_a_camera_can_point_at(): void
    {
        $this->assertStringContainsString(
            'A shot is what a camera can point at',
            $this->proposalInstructions(),
        );
    }

    /**
     * The planner reads the same subject rules the writer does.
     *
     * Moving the decision up a layer without them produced a month of shots the
     * writer would have had to refuse: a printed checklist, a phone showing an
     * arrival time, a tablet showing a checklist, and three empty rooms. One
     * source, both readers — this is the third time a rule typed out twice went
     * stale in its second copy.
     */
    #[Test]
    public function the_planner_and_the_writer_read_the_same_subject_rules(): void
    {
        $rules = SocialImagePrompt::subjectRules();

        $this->assertStringContainsString($rules, $this->proposalInstructions());

        // And the rules themselves still say the two things that matter.
        $this->assertStringContainsString('never contains an object that exists to be read', $rules);
        $this->assertStringContainsString('never built around an empty room', $rules);
        $this->assertStringContainsString('A person may be in it', $rules);
    }

    /**
     * The setting reaches the writer, not only the provider.
     *
     * Held on the provider side alone it lost every time. The brief behind the
     * bathroom nobody would pay to have cleaned asked in its own words for a
     * "slightly frayed" cloth, a "scuffed brush handle" and "grime caught in
     * the narrow joint" — so redrawing it under a better house rule redrew the
     * same bathroom. A provider handed a subject and a rule against it draws
     * the subject; this codebase has now learned that three times.
     */
    #[Test]
    public function the_writer_is_told_whose_place_the_picture_is_set_in(): void
    {
        $rules = SocialImagePrompt::settingRules();

        $this->assertStringContainsString("one of this business's own customers", $rules);
        $this->assertStringContainsString('Unstyled describes the photograph, not the place', $rules);
    }

    /**
     * And it says nothing about houses, because this engine is not only for
     * cleaning companies.
     *
     * The first version of this rule described a room, its fittings and its
     * tiling. The Studio's own tests run a SaaS project aimed at founders;
     * handed to a tenant whose subject is an office or a product on a desk,
     * residential art direction is one more contradiction for the model to
     * resolve, and it resolves contradictions by picking one.
     */
    #[Test]
    public function the_setting_rule_assumes_no_particular_kind_of_business(): void
    {
        $rules = SocialImagePrompt::settingRules();

        foreach (['home', 'room', 'house', 'tiling', 'fittings', 'apartment'] as $residential) {
            $this->assertStringNotContainsStringIgnoringCase($residential, $rules);
        }
    }

    /** Who the customers are comes from the brand, which is the part that varies. */
    #[Test]
    public function the_brand_supplies_who_the_customers_are(): void
    {
        $brief = new BrandBrief;
        // Written in a textarea, so it arrives as bullets more often than not —
        // and bullets dropped mid-paragraph read as bullets.
        $brief->audience = "- busy households\n- Lisbon property owners\n- tenants and property managers";

        $this->assertStringContainsString(
            'The customers this business serves are: busy households, Lisbon property owners, '
                .'tenants and property managers.',
            SocialImagePrompt::settingRules($brief),
        );

        // A tenant with nothing written down gets the relationship and no
        // invented audience, rather than a sentence trailing off after a colon.
        $this->assertStringNotContainsString(
            'The customers this business serves are:',
            SocialImagePrompt::settingRules(new BrandBrief),
        );
    }

    /**
     * A fresh answer that left the key out is sent back, not stored quietly.
     *
     * Nothing downstream refuses a null shot — the column is nullable because
     * every idea planned before it existed has none — so a model that simply
     * omitted it would have restored the fallback this change removes, with no
     * sign on any screen that it had.
     */
    #[Test]
    public function a_proposal_that_omits_a_shot_is_corrected(): void
    {
        $findings = $this->missingShots([
            ['idea_key' => 'reset-the-table', 'shot' => 'a folded chair against a cleared table'],
            ['idea_key' => 'the-arrival-window', 'shot' => null],
            ['idea_key' => 'colour-coded-cloths', 'shot' => '   '],
        ]);

        $this->assertCount(1, $findings);
        // Named, because "three ideas have no shot" only sends the model looking.
        $this->assertStringContainsString('the-arrival-window', $findings[0]);
        $this->assertStringContainsString('colour-coded-cloths', $findings[0]);
        $this->assertStringNotContainsString('reset-the-table', $findings[0]);
    }

    /** And a complete answer is not nagged. */
    #[Test]
    public function a_proposal_with_every_shot_passes(): void
    {
        $this->assertSame([], $this->missingShots([
            ['idea_key' => 'one', 'shot' => 'a key on an entrance console'],
            ['idea_key' => 'two', 'shot' => 'balcony doors moving a sheer curtain'],
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $ideas
     * @return list<string>
     */
    private function missingShots(array $ideas): array
    {
        $method = new ReflectionMethod(ContentStudioAssistant::class, 'missingShots');
        $method->setAccessible(true);

        /** @var list<string> $findings */
        $findings = $method->invoke(app(ContentStudioAssistant::class), $ideas);

        return $findings;
    }

    private function proposalInstructions(): string
    {
        $method = new ReflectionMethod(ContentStudioAssistant::class, 'proposalInstructions');
        $method->setAccessible(true);

        /** @var string $instructions */
        $instructions = $method->invoke(app(ContentStudioAssistant::class));

        return $instructions;
    }
}
