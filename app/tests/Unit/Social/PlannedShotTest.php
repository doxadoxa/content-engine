<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\ContentStudio\ContentStudioAssistant;
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
        $this->assertStringContainsString('no clipboards', $rules);
        $this->assertStringContainsString('never built around an empty room', $rules);
        $this->assertStringContainsString('A person may be in it', $rules);
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
