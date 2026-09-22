<?php

declare(strict_types=1);

namespace Tests\Feature\Facts;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Ai\ModelRequest;
use App\Ai\UnmeteredSession;
use App\Facts\AssessFactClaims;
use App\Facts\FactSection;
use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AssessFactClaimsTest extends TestCase
{
    use RefreshDatabase;

    private BusinessFactVersion $fact;

    private FakeModelGateway $gateway;

    private UnmeteredSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentProject::class)->set(Project::factory()->create());
        $owner = User::factory()->create();
        $fact = BusinessFact::query()->create(['name' => 'Service area']);
        $this->fact = BusinessFactVersion::query()->create(['business_fact_id' => $fact->id, 'version' => 1, 'statement' => 'Cleaning Point serves Lisbon only.', 'source_url' => 'https://cleaningpoint.net/service-area', 'source_note' => 'Owner supplied service area.', 'status' => 'confirmed', 'confirmed_by' => $owner->id, 'confirmed_at' => now(), 'review_due_at' => now()->addMonth(), 'created_by' => $owner->id]);
        $fact->forceFill(['current_version_id' => $this->fact->id])->save();
        /** @var FakeModelGateway $gateway */
        $gateway = app(ModelGateway::class);
        $this->gateway = $gateway;
        $this->session = new UnmeteredSession($gateway);
    }

    #[Test]
    public function proposed_claims_preserve_exact_unicode_quotation_and_approved_fact_provenance(): void
    {
        $quote = 'Cleaning Point também trabalha no Porto.';
        $text = 'Olá 👋. '.$quote;
        $this->gateway->willAnswerRole('factcheck', $this->reply($quote));
        $result = app(AssessFactClaims::class)->assess($this->session, [new FactSection('answer:1', $text, ['business' => 'Cleaning Point'], [['id' => 'cite:1', 'url' => 'https://directory.test/list', 'title' => 'Directory']])], [$this->fact]);
        $this->assertSame('complete', $result->status);
        $this->assertSame($quote, $result->findings[0]->exactQuote);
        $this->assertSame(mb_strlen('Olá 👋. '), $result->findings[0]->startCodepoint);
        $this->assertSame(mb_strlen($text), $result->findings[0]->endCodepoint);
        $this->assertSame($this->fact->id, $result->findings[0]->factVersionId);
        $this->assertSame(mb_strlen($text), $result->coverage['assessed_characters']);
        $this->assertSame('fake', $result->checker['calls'][0]['provider']);
        $this->assertGreaterThan(0, $result->checker['calls'][0]['input_tokens']);
        $this->assertCount(1, $this->gateway->sent());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidFindings(): iterable
    {
        yield 'invented quote' => [['exact_quote' => 'Invented claim.']];
        yield 'invented fact' => [['fact_version_id' => 'made-up-fact']];
        yield 'invented citation' => [['reference_ids' => ['made-up-citation']]];
        yield 'missing grounding' => [['fact_version_id' => null]];
        yield 'unknown relation' => [['relation' => 'proved-wrong']];
    }

    /** @param array<string, mixed> $change */
    #[Test]
    #[DataProvider('invalidFindings')]
    public function unverifiable_findings_fail_closed_and_do_not_claim_coverage(array $change): void
    {
        $this->gateway->willAnswerRole('factcheck', $this->reply('Cleaning Point serves Porto.', $change));
        $result = app(AssessFactClaims::class)->assess($this->session, [new FactSection('answer', 'Cleaning Point serves Porto.')], [$this->fact]);
        $this->assertSame('unavailable', $result->status);
        $this->assertSame([], $result->findings);
        $this->assertSame(0, $result->coverage['assessed_characters']);
        $this->assertCount(1, $result->checker['calls']);
    }

    #[Test]
    public function superseded_or_expired_facts_refuse_paid_work(): void
    {
        $this->fact->fact?->forceFill(['current_version_id' => null])->save();
        $result = app(AssessFactClaims::class)->assess($this->session, [new FactSection('answer', 'A business claim.')], [$this->fact->fresh()]);
        $this->assertSame('unavailable', $result->status);
        $this->assertCount(0, $this->gateway->sent());
        $this->fact->fact?->forceFill(['current_version_id' => $this->fact->id])->save();
        $this->travel(2)->months();
        $result = app(AssessFactClaims::class)->assess($this->session, [new FactSection('answer', 'A business claim.')], [$this->fact->fresh()]);
        $this->assertSame('unavailable', $result->status);
        $this->assertCount(0, $this->gateway->sent());
    }

    #[Test]
    public function complete_text_is_assessed_in_chunks_with_global_offsets_and_budget_coverage(): void
    {
        $quote = 'Cleaning Point serves Porto.';
        $prefix = str_repeat('A', AssessFactClaims::CHUNK_CHARACTERS);
        $this->gateway->willAnswer(['{"assessed_entire_section":true,"findings":[]}', $this->reply($quote)]);
        $result = app(AssessFactClaims::class)->assess($this->session, [new FactSection('answer', $prefix.$quote)], [$this->fact]);
        $this->assertSame('complete', $result->status);
        $this->assertSame(AssessFactClaims::CHUNK_CHARACTERS, $result->findings[0]->startCodepoint);
        $this->assertSame(2, $result->coverage['validated_calls']);
        $this->gateway->willAnswerRole('factcheck', '{"assessed_entire_section":true,"findings":[]}');
        $result = app(AssessFactClaims::class)->assess($this->session, [new FactSection('long', str_repeat('a', AssessFactClaims::CHUNK_CHARACTERS * 13))], [$this->fact]);
        $this->assertSame('partial', $result->status);
        $this->assertSame(12, $result->coverage['attempted_calls']);
        $this->assertSame(13, $result->coverage['planned_calls']);
        $this->assertSame(AssessFactClaims::CHUNK_CHARACTERS * 12, $result->coverage['assessed_characters']);
    }

    #[Test]
    public function an_ambiguous_repeated_quote_requires_an_exact_occurrence(): void
    {
        $quote = 'Cleaning Point serves Porto.';
        $section = new FactSection('answer', $quote.' '.$quote);
        $this->gateway->willAnswerRole('factcheck', $this->reply($quote));
        $this->assertSame('unavailable', app(AssessFactClaims::class)->assess($this->session, [$section], [$this->fact])->status);
        $this->gateway->willAnswerRole('factcheck', $this->reply($quote, ['start_codepoint' => mb_strlen($quote.' ')]));
        $result = app(AssessFactClaims::class)->assess($this->session, [$section], [$this->fact]);
        $this->assertSame('complete', $result->status);
        $this->assertSame(mb_strlen($quote.' '), $result->findings[0]->startCodepoint);
    }

    #[Test]
    public function a_fact_changed_during_the_model_call_invalidates_findings_but_retains_usage(): void
    {
        $quote = 'Cleaning Point serves Porto.';
        $this->gateway->willAnswerUsing(function () use ($quote): string {
            BusinessFact::query()->whereKey($this->fact->business_fact_id)->update(['current_version_id' => null]);

            return $this->reply($quote);
        });
        $result = app(AssessFactClaims::class)->assess($this->session, [new FactSection('answer', $quote)], [$this->fact]);
        $this->assertSame('unavailable', $result->status);
        $this->assertSame([], $result->findings);
        $this->assertCount(1, $result->checker['calls']);
        $this->assertCount(1, $result->checker['invalidated_findings']);
        $this->assertStringContainsString('changed or expired', implode(' ', $result->limitations));
    }

    #[Test]
    public function neighboring_context_preserves_negation_across_chunk_boundaries(): void
    {
        $lead = str_repeat('x', AssessFactClaims::CHUNK_CHARACTERS - mb_strlen('Cleaning Point does not '));
        $claim = 'Cleaning Point does not serve Porto.';
        $this->gateway->willAnswerUsing(function (ModelRequest $request) use ($claim): string {
            $source = json_decode($request->prompt, true, flags: JSON_THROW_ON_ERROR)['source'];
            $this->assertStringContainsString($claim, $source['text']);

            return $this->reply($claim, ['relation' => 'supported']);
        });
        $result = app(AssessFactClaims::class)->assess($this->session, [new FactSection('answer', $lead.$claim)], [$this->fact]);
        $this->assertSame('complete', $result->status);
        $this->assertSame(2, $result->coverage['validated_calls']);
        $this->assertCount(1, $result->findings);
        $this->assertSame('supported', $result->findings[0]->relation);
        $this->assertSame(mb_strlen($lead), $result->findings[0]->startCodepoint);
        $this->assertSame(mb_strlen($lead.$claim), $result->coverage['assessed_characters']);
    }

    /** @param array<string, mixed> $change */
    private function reply(string $quote, array $change = []): string
    {
        return json_encode(['assessed_entire_section' => true, 'findings' => [[...['exact_quote' => $quote, 'relation' => 'contradicted', 'fact_version_id' => $this->fact->id,
            'reason' => 'The quoted Porto service conflicts with the confirmed Lisbon-only service area.', 'reference_ids' => []], ...$change]]], JSON_THROW_ON_ERROR);
    }
}
