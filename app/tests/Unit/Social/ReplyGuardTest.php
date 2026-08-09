<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Models\BrandBrief;
use App\Models\Project;
use App\Social\ReplyGuard;
use App\Social\ReplyGuardFinding;
use App\Social\ReplyGuardVerdict;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Social\EngageTest;
use Tests\TestCase;

/**
 * The deterministic gate in front of every reply, on its own (§4.3, §7, §10).
 *
 * {@see EngageTest} proves the guard runs at the send
 * boundary and that a blocked draft reaches the operator with its reason. What
 * it cannot do in a request-shaped test is exercise the rules themselves — the
 * blank and length codes never appear there at all, and the word-boundary logic
 * the guard's docblock justifies at length for Cyrillic and Portuguese is
 * reached through exactly one English brief.
 *
 * No database. The guard reads a text, a project and a brief, and both models
 * can be made in memory; a rule that needed a row to be tested would be a rule
 * with a dependency it does not have.
 */
final class ReplyGuardTest extends TestCase
{
    private ReplyGuard $guard;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-09 12:00:00');

        $this->guard = new ReplyGuard;
        $this->project = new Project(['name' => 'Cleaning Point']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_reply_the_model_never_wrote_is_blank_and_blocking(): void
    {
        // The state a failed drafting run leaves behind, and it has to say so:
        // §7's rule is that a silence carries its reason.
        $verdict = $this->guard->check("  \n\t ", $this->project, null);

        $this->assertFalse($verdict->passed());
        $this->assertFalse($verdict->sendable());
        $this->assertCount(1, $verdict->findings);
        $this->assertSame(ReplyGuard::BLANK, $verdict->findings[0]->code);
        $this->assertTrue($verdict->findings[0]->blocking);
    }

    #[Test]
    public function a_blank_reply_is_the_only_finding_because_nothing_else_has_anything_to_look_at(): void
    {
        $brief = new BrandBrief(['forbidden_topics' => ['ICO']]);

        $verdict = $this->guard->check('', $this->project, $brief);

        $this->assertSame([ReplyGuard::BLANK], array_map(
            static fn (ReplyGuardFinding $finding): string => $finding->code,
            $verdict->findings,
        ));
    }

    #[Test]
    public function a_reply_over_the_platform_limit_is_blocking_and_says_both_numbers(): void
    {
        config()->set('social.threads.text_limit', 500);

        // Multi-byte, because the limit is characters and not bytes: 501
        // Cyrillic characters is 1002 bytes and still one over the limit.
        $verdict = $this->guard->check(str_repeat('я', 501), $this->project, null);

        $this->assertFalse($verdict->sendable());
        $this->assertCount(1, $verdict->findings);
        $this->assertSame(ReplyGuard::LENGTH, $verdict->findings[0]->code);
        $this->assertStringContainsString('501 characters', $verdict->findings[0]->detail);
        $this->assertStringContainsString('over 500', $verdict->findings[0]->detail);

        // Exactly at the limit is fine — the platform's rule is "over".
        $this->assertTrue($this->guard->check(str_repeat('я', 500), $this->project, null)->sendable());
    }

    #[Test]
    public function the_limit_is_the_platforms_and_comes_from_config(): void
    {
        config()->set('social.threads.text_limit', 42);

        $this->assertSame(42, $this->guard->textLimit());
        $this->assertFalse($this->guard->check(str_repeat('a', 43), $this->project, null)->sendable());
    }

    #[Test]
    public function a_forbidden_topic_is_matched_as_a_whole_word_and_not_as_a_substring(): void
    {
        // The guard's own example. A brief that forbids "ICO" must not match
        // "medico", or every reply arrives blocked and the guard gets switched
        // off — which is the failure mode a guard has.
        $brief = new BrandBrief(['forbidden_topics' => ['ICO']]);

        $this->assertTrue(
            $this->guard->check('Fale com o seu médico antes de decidir.', $this->project, $brief)->sendable(),
        );

        $this->assertFalse(
            $this->guard->check('We are not doing an ICO.', $this->project, $brief)->sendable(),
        );
    }

    #[Test]
    public function the_word_boundary_holds_for_cyrillic_and_portuguese(): void
    {
        // This is the whole reason the pattern is `\p{L}` rather than `\b`.
        // `\b` is ASCII-shaped even under `/u`: between "ставк" and "и" it sees
        // a letter next to a letter and holds, but between a space and "с" it
        // sees a non-word character next to a non-word character and does not
        // fire at all — so `\bставка\b` never matches a Cyrillic sentence.
        $russian = new BrandBrief(['forbidden_topics' => ['ставка']]);

        $this->assertFalse(
            $this->guard->check('Наша ставка на этот квартал.', $this->project, $russian)->sendable(),
            'A Cyrillic forbidden topic must be found when it stands as a word.',
        );

        $this->assertTrue(
            $this->guard->check('Мы обсуждали ставкикросс и ничего больше.', $this->project, $russian)->sendable(),
            'A Cyrillic forbidden topic must not match inside a longer word.',
        );

        $portuguese = new BrandBrief(['forbidden_topics' => ['juro']]);

        $this->assertFalse(
            $this->guard->check('O juro é fixo.', $this->project, $portuguese)->sendable(),
        );

        $this->assertTrue(
            $this->guard->check('Nós juramos que não.', $this->project, $portuguese)->sendable(),
            'A Portuguese forbidden topic must not match inside a longer word.',
        );
    }

    #[Test]
    public function a_forbidden_topic_is_matched_regardless_of_case_and_of_regex_characters_in_it(): void
    {
        $brief = new BrandBrief(['forbidden_topics' => ['guaranteed returns', 'A.P.R.', '  ', '']]);

        $this->assertFalse(
            $this->guard->check('You get GUARANTEED RETURNS every month.', $this->project, $brief)->sendable(),
        );

        // Quoted rather than compiled: a topic with a dot in it must not become
        // a wildcard that matches "APRx".
        $this->assertFalse(
            $this->guard->check('The A.P.R. is fixed.', $this->project, $brief)->sendable(),
        );

        $this->assertTrue(
            $this->guard->check('The APRx is fixed.', $this->project, $brief)->sendable(),
        );

        // And an empty entry in the list is skipped rather than matching
        // everything, which is what a brief edited on a phone produces.
        $this->assertTrue(
            $this->guard->check('Nothing controversial here.', $this->project, $brief)->sendable(),
        );
    }

    #[Test]
    public function every_forbidden_topic_the_reply_names_becomes_its_own_finding(): void
    {
        $brief = new BrandBrief(['forbidden_topics' => ['ICO', 'airdrop']]);

        $verdict = $this->guard->check('An ICO and an airdrop, in one sentence.', $this->project, $brief);

        $this->assertCount(2, $verdict->findings);
        $this->assertSame(
            [ReplyGuard::FORBIDDEN_TOPIC, ReplyGuard::FORBIDDEN_TOPIC],
            array_map(static fn (ReplyGuardFinding $f): string => $f->code, $verdict->findings),
        );
        // Both carry the same code, which is why the screen keys findings by
        // position: two topics used to be two React children with one key.
        $this->assertStringContainsString('ICO', $verdict->findings[0]->detail);
        $this->assertStringContainsString('airdrop', $verdict->findings[1]->detail);
    }

    #[Test]
    public function a_project_with_no_brief_is_a_missing_topic_list_and_not_a_refusal(): void
    {
        // A project can be onboarded before anybody writes a brief, and §4.2's
        // latency does not wait for one.
        $this->assertTrue($this->guard->check('Anything at all.', $this->project, null)->passed());
    }

    #[Test]
    public function the_factcheck_finding_is_absent_when_the_check_found_nothing(): void
    {
        $this->assertNull($this->guard->factcheck([], blocking: true));

        $finding = $this->guard->factcheck(['no source for 0.2%', 'no source for 1.4%'], blocking: true);

        $this->assertNotNull($finding);
        $this->assertSame(ReplyGuard::FACTCHECK, $finding->code);
        $this->assertTrue($finding->blocking);
        $this->assertStringContainsString('no source for 0.2%; no source for 1.4%', $finding->detail);
    }

    #[Test]
    public function a_verdict_survives_the_round_trip_through_the_column_it_is_stored_in(): void
    {
        // `interactions.draft_guard` is written by toArray() and read by
        // fromArray(), and §7's explanation on the row is whatever survives.
        $verdict = new ReplyGuardVerdict([
            new ReplyGuardFinding(ReplyGuard::FACTCHECK, 'No source for 0.2%.', blocking: true),
            new ReplyGuardFinding('advisory', 'Reads a little cold.', blocking: false),
        ], Carbon::now());

        $read = ReplyGuardVerdict::fromArray($verdict->toArray());

        $this->assertEquals($verdict->findings, $read->findings);
        $this->assertNotNull($read->checkedAt);
        $this->assertTrue(Carbon::now()->eq($read->checkedAt));

        $this->assertFalse($read->sendable());
        $this->assertSame('No source for 0.2%.', $read->firstBlocking());
        $this->assertNotNull($read->finding(ReplyGuard::FACTCHECK));
        $this->assertNull($read->finding('nothing-like-this'));
    }

    #[Test]
    public function a_stored_document_that_makes_no_sense_reads_as_no_findings_rather_than_throwing(): void
    {
        // The column is nullable and hand-editable, and a duty screen that 500s
        // on a malformed json document is worse than one that shows no findings.
        $this->assertTrue(ReplyGuardVerdict::fromArray(null)->passed());
        $this->assertTrue(ReplyGuardVerdict::fromArray([])->passed());
        $this->assertTrue(ReplyGuardVerdict::fromArray(['findings' => 'nonsense'])->passed());

        $salvaged = ReplyGuardVerdict::fromArray(['findings' => [['detail' => 'orphan']]]);

        $this->assertCount(1, $salvaged->findings);
        $this->assertSame('unknown', $salvaged->findings[0]->code);
        $this->assertFalse($salvaged->findings[0]->blocking);
    }

    #[Test]
    public function a_finding_can_change_its_weight_without_changing_its_words(): void
    {
        // The fact-check needs this: it is decided once while the draft is
        // written and weighed again in the context it is being sent in.
        $finding = new ReplyGuardFinding(ReplyGuard::FACTCHECK, 'No source for 0.2%.', blocking: false);
        $blocking = $finding->blocking(true);

        $this->assertTrue($blocking->blocking);
        $this->assertSame($finding->detail, $blocking->detail);
        $this->assertFalse($finding->blocking, 'A finding is readonly; weighing it makes a new one.');
    }
}
