<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Models\Project;
use App\Models\PurchaseEvent;
use App\Models\PurchaseRecord;
use App\Models\PurchaseSource;
use App\Models\SitePage;
use App\Models\User;
use App\Pages\TrackedPages;
use App\Purchases\PurchaseIntake;
use App\Purchases\PurchasePayload;
use App\Purchases\PurchaseReport;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class PurchaseIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private PurchaseSource $source;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T12:00:00Z'));
        $this->project = Project::factory()->create(['website_url' => 'https://cleaning.example']);
        app(CurrentProject::class)->set($this->project);
        $this->source = PurchaseSource::query()->create(['name' => 'Payment ledger', 'kind' => 'webhook', 'secret' => 'test-signing-key', 'is_enabled' => true, 'is_primary' => true]);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
    }

    public function test_signature_and_time_are_required_before_accepting_or_revealing_source_data(): void
    {
        $this->send($this->payload(), secret: 'wrong-key')->assertUnauthorized();
        $this->send($this->payload(), timestamp: now()->subMinutes(6)->getTimestamp())->assertUnauthorized();
        $this->postJson('/api/purchases/'.$this->source->id, $this->payload())->assertUnauthorized();
        $this->assertSame(0, PurchaseRecord::query()->count());
        $this->assertStringNotContainsString('test-signing-key', json_encode($this->source, JSON_THROW_ON_ERROR));
        $this->assertNotSame('test-signing-key', $this->source->getRawOriginal('secret'));
    }

    public function test_signed_identity_selects_the_tenant_even_when_another_project_is_in_the_browser_session(): void
    {
        $other = Project::factory()->create();
        $stranger = User::factory()->create();
        $stranger->projects()->attach($other, ['role' => 'owner']);
        $this->actingAs($stranger);
        $this->send($this->payload())->assertOk();
        $this->assertSame($other->id, app(CurrentProject::class)->id(), 'Signed intake restores the session tenant.');
        $this->assertSame(0, PurchaseRecord::query()->count());
        $this->assertSame($this->project->id, PurchaseRecord::acrossProjects()->sole()->project_id);
        $this->get('/purchases')->assertInertia(fn (AssertableInertia $page) => $page->has('sources', 0)->has('records.data', 0));
        $this->patchJson('/purchases/sources/'.$this->source->id, ['action' => 'primary'])->assertNotFound();
    }

    public function test_retry_is_idempotent_and_a_changed_payload_cannot_reuse_event_identity(): void
    {
        $payload = $this->payload();
        $this->send($payload)->assertOk()->assertJsonPath('disposition', 'applied');
        $this->send($payload)->assertOk()->assertJsonPath('replayed', true);
        $this->send([...$payload, 'amount_minor' => 30000])->assertConflict();
        $this->assertSame(1, PurchaseRecord::query()->count());
        $this->assertSame(1, PurchaseEvent::query()->count());
        $this->assertSame(12500, PurchaseRecord::query()->sole()->amount_minor);
    }

    public function test_revisions_correct_a_sale_and_stale_delivery_cannot_restore_an_old_amount(): void
    {
        $old = $this->payload();
        $this->send($old)->assertOk();
        $corrected = $this->payload(['revision' => 3, 'amount_minor' => 10000, 'evidence' => 'ledger:1 correction']);
        $this->send($corrected)->assertOk();
        $this->send($this->payload(['revision' => 2, 'amount_minor' => 12000]))->assertOk()->assertJsonPath('disposition', 'stale');
        $this->send([...$corrected, 'event_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('disposition', 'duplicate');
        $this->send($this->payload(['revision' => 3, 'amount_minor' => 9999]))->assertConflict();
        $this->assertSame(10000, PurchaseRecord::query()->sole()->amount_minor);
        $this->assertSame(0, PurchaseRecord::query()->sole()->refunded_minor, 'A correction is not a refund.');
        $this->assertSame(4, PurchaseEvent::query()->count());
    }

    public function test_partial_payments_unpaid_bookings_and_refunds_are_not_extra_sales(): void
    {
        $this->send($this->payload(['status' => 'partially_paid', 'amount_minor' => 5000, 'purchased_at' => null]))->assertOk();
        $this->send($this->payload(['revision' => 2]))->assertOk();
        $this->send($this->payload(['revision' => 3, 'status' => 'refunded', 'refunded_minor' => 12500]))->assertOk();
        $this->send($this->payload(['transaction_id' => 'cleaning:2', 'status' => 'unpaid', 'purchased_at' => null, 'amount_minor' => 0]))->assertOk();
        $report = $this->report();
        $this->assertSame('unverified', $report['status']);
        $this->assertSame(1, $report['currencies'][0]['completed_sales']);
        $this->assertSame(1, $report['currencies'][0]['fully_refunded_sales']);
        $this->assertSame(0, $report['currencies'][0]['net_minor']);
        $this->assertSame(1, $report['currencies'][0]['unknown_customer_sales']);
        $this->send($this->payload(['transaction_id' => 'invalid', 'refunded_minor' => 13000]))->assertUnprocessable();
        $this->send($this->payload(['transaction_id' => 'free', 'amount_minor' => 0]))->assertUnprocessable();
        $this->send($this->payload(['transaction_id' => 'partial', 'status' => 'partially_paid']))->assertUnprocessable();
    }

    public function test_canonical_attribution_is_exact_and_never_invented_for_missing_consent_or_history(): void
    {
        $url = 'https://cleaning.example/en/services/deep';
        $page = SitePage::query()->create(['url' => $url, 'title' => 'Deep cleaning', 'canonical_url' => $url, 'canonical_hash' => hash('sha256', $url), 'locale' => 'en', 'tracked_at' => now(), 'is_article' => false]);
        $this->send($this->payload(['attribution_status' => 'attributed', 'landing_url' => $url.'?utm_source=google']))->assertOk();
        $this->assertSame($page->id, PurchaseRecord::query()->sole()->site_page_id);
        $this->send($this->payload(['transaction_id' => 'denied', 'attribution_status' => 'consent_denied', 'landing_url' => $url]))->assertOk();
        $this->send($this->payload(['transaction_id' => 'old', 'attribution_status' => 'attributed', 'landing_url' => $url, 'purchased_at' => '2026-08-01T12:00:00Z']))->assertOk();
        $this->send($this->payload(['transaction_id' => 'unknown', 'attribution_status' => 'attributed', 'landing_url' => 'https://cleaning.example/another']))->assertOk();
        $this->assertSame(1, PurchaseRecord::query()->whereNotNull('site_page_id')->count());
        $this->assertSame(3, PurchaseRecord::query()->whereNull('landing_url')->count());
        $this->assertNull(PurchaseEvent::query()->where('payload->transaction_id', 'denied')->sole()->payload['landing_url']);
        $this->send($this->payload(['transaction_id' => 'foreign', 'attribution_status' => 'attributed', 'landing_url' => 'https://another.example/en/services/deep']))->assertUnprocessable();
    }

    public function test_disconnected_sources_and_unknown_history_are_not_zero(): void
    {
        $report = $this->report();
        $this->assertSame('unavailable', $report['status']);
        $this->assertNull($report['currencies']);
        $this->send($this->payload())->assertOk();
        $this->assertNotNull($this->source->refresh()->first_received_at);
        $this->assertNull($this->source->verified_at);
        $report = $this->report();
        $this->assertContains('Tracking does not cover this entire window; missing history is unavailable.', $report['limitations']);
    }

    public function test_primary_source_and_currency_groups_prevent_combining_duplicates_and_money_units(): void
    {
        $this->send($this->payload())->assertOk();
        $this->send($this->payload(['transaction_id' => 'usd:1', 'currency' => 'USD', 'amount_minor' => 9000]))->assertOk();
        $manual = PurchaseSource::query()->create(['name' => 'Manual ledger', 'kind' => 'manual', 'is_enabled' => true, 'is_primary' => false]);
        app(PurchaseIntake::class)->receive($manual, PurchasePayload::from($this->payload(['amount_minor' => 25000])));
        $report = $this->report();
        $this->assertCount(2, $report['currencies']);
        $this->assertSame(2, array_sum(array_column($report['currencies'], 'completed_sales')));
        $this->actingAs($this->owner)->patch('/purchases/sources/'.$manual->id, ['action' => 'primary'])->assertRedirect();
        $this->assertSame(1, PurchaseSource::query()->where('is_primary', true)->count());
        $this->assertTrue($manual->refresh()->is_primary);
        $this->assertFalse($this->source->refresh()->is_primary);
    }

    public function test_owner_verification_requires_a_received_completed_sale_and_rotation_revokes_it(): void
    {
        $this->actingAs($this->owner)->patchJson('/purchases/sources/'.$this->source->id, ['action' => 'verify', 'transaction_id' => 'cleaning:1', 'note' => 'Compared ledger'])->assertUnprocessable();
        $this->send($this->payload())->assertOk();
        $this->patch('/purchases/sources/'.$this->source->id, ['action' => 'verify', 'transaction_id' => 'cleaning:1', 'note' => 'EUR 125 paid, ledger correction and refund test matched.'])->assertRedirect();
        $this->assertNotNull($this->source->refresh()->verified_at);
        $this->patch('/purchases/sources/'.$this->source->id, ['action' => 'rotate'])->assertRedirect();
        $this->assertNull($this->source->refresh()->verified_at);
        $this->send($this->payload())->assertUnauthorized();
        $this->assertSame(1, PurchaseRecord::query()->count());
    }

    public function test_non_owners_cannot_change_sources_or_manually_record_sales(): void
    {
        $member = User::factory()->create();
        $member->projects()->attach($this->project, ['role' => 'member']);
        $this->actingAs($member)->postJson('/purchases/sources', ['name' => 'Injected', 'kind' => 'manual'])->assertForbidden();
        $this->patchJson('/purchases/sources/'.$this->source->id, ['action' => 'pause'])->assertForbidden();
        $this->postJson('/purchases/records', ['source_id' => $this->source->id])->assertForbidden();
        $this->get('/purchases')->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false)->missing('sources.0.secret'));
    }

    public function test_manual_reconciliation_is_labelled_and_requires_new_revision_for_corrections(): void
    {
        $source = PurchaseSource::query()->create(['name' => 'Manual', 'kind' => 'manual', 'is_enabled' => true, 'is_primary' => false]);
        $payload = [...$this->payload(), 'source_id' => $source->id];
        $this->actingAs($this->owner)->post('/purchases/records', $payload)->assertRedirect();
        $this->assertSame($this->owner->id, PurchaseEvent::query()->sole()->getAttribute('recorded_by'));
        $this->assertSame('unattributed', PurchaseRecord::query()->sole()->attribution_status);
        $this->postJson('/purchases/records', [...$payload, 'event_id' => (string) Str::uuid(), 'amount_minor' => 8000])->assertConflict();
        $this->post('/purchases/records', [...$payload, 'event_id' => (string) Str::uuid(), 'revision' => 2, 'amount_minor' => 8000])->assertRedirect();
        $this->assertSame(1, PurchaseRecord::query()->count());
        $this->assertSame(8000, PurchaseRecord::query()->sole()->amount_minor);
        $this->postJson('/purchases/records', [...$payload, 'source_id' => $this->source->id])->assertNotFound();
    }

    public function test_pausing_collection_preserves_records_and_refuses_new_receipts(): void
    {
        $this->send($this->payload())->assertOk();
        $this->actingAs($this->owner)->patch('/purchases/sources/'.$this->source->id, ['action' => 'pause'])->assertRedirect();
        $this->send($this->payload(['revision' => 2]))->assertUnauthorized();
        $this->assertSame(1, PurchaseRecord::query()->count());
        $this->assertSame(1, PurchaseEvent::query()->count());
    }

    public function test_unexpected_personal_fields_and_oversized_payloads_are_rejected(): void
    {
        $this->send($this->payload(['customer_email' => 'do-not-store@example.test']))->assertUnprocessable();
        $this->send($this->payload(['evidence' => str_repeat('x', 70000)]))->assertStatus(413);
        $this->assertSame(0, PurchaseEvent::query()->count());
    }

    public function test_retained_deposits_and_cancelled_receipts_remain_in_balances_without_becoming_completed_sales(): void
    {
        $this->send($this->payload(['status' => 'partially_paid', 'amount_minor' => 5000, 'purchased_at' => null]))->assertOk();
        $this->send($this->payload(['transaction_id' => 'cancelled:1', 'status' => 'cancelled', 'amount_minor' => 2000, 'purchased_at' => null]))->assertOk();
        $report = $this->report();
        $this->assertSame([], $report['currencies']);
        $this->assertSame(7000, $report['recorded_balances'][0]['received_minor']);
        $this->assertSame(7000, $report['recorded_balances'][0]['net_minor']);
        $this->assertSame(1, $report['recorded_balances'][0]['partially_paid']);
        $this->assertSame(1, $report['recorded_balances'][0]['cancelled']);
    }

    public function test_later_receipt_correction_preserves_actual_refund_and_flags_inconsistent_ledger(): void
    {
        $this->send($this->payload(['status' => 'refunded', 'refunded_minor' => 12500]))->assertOk();
        $this->send($this->payload(['revision' => 2, 'status' => 'reconciliation_required', 'amount_minor' => 5000, 'refunded_minor' => 12500]))->assertOk();
        $report = $this->report();
        $this->assertSame(12500, $report['recorded_balances'][0]['refunded_minor']);
        $this->assertSame(-7500, $report['recorded_balances'][0]['net_minor']);
        $this->assertSame(1, $report['recorded_balances'][0]['reconciliation_required']);
        $this->assertSame(0, $report['currencies'][0]['completed_sales']);
    }

    public function test_database_references_cannot_mix_tenants_or_purchase_sources(): void
    {
        $this->send($this->payload())->assertOk();
        $other = Project::factory()->create();
        $otherSource = app(CurrentProject::class)->run($other, fn (): PurchaseSource => PurchaseSource::query()->create(['name' => 'Other ledger', 'kind' => 'manual']));
        $otherPage = app(CurrentProject::class)->run($other, fn (): SitePage => SitePage::query()->create(['url' => 'https://another.example/page', 'title' => 'Other page', 'is_article' => false]));
        $record = PurchaseRecord::query()->sole();
        $event = PurchaseEvent::query()->sole();
        foreach ([
            fn () => DB::table('purchase_records')->where('id', $record->id)->update(['purchase_source_id' => $otherSource->id]),
            fn () => DB::table('purchase_records')->where('id', $record->id)->update(['site_page_id' => $otherPage->id]),
            fn () => DB::table('purchase_events')->where('id', $event->id)->update(['purchase_source_id' => $otherSource->id]),
        ] as $write) {
            $rejected = false;
            try {
                DB::transaction($write);
            } catch (QueryException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'A composite reference must enforce the tenant even if service code is bypassed.');
        }
        $sameTenantSource = PurchaseSource::query()->create(['name' => 'Another source', 'kind' => 'manual']);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('purchase_events')->where('id', $event->id)->update(['purchase_source_id' => $sameTenantSource->id]));
    }

    public function test_captured_landing_alias_is_attributed_to_the_single_canonical_page(): void
    {
        Http::fake([
            'cleaning.example/*' => Http::response('<html lang="en"><head><title>Deep cleaning</title><link rel="canonical" href="/en/services/deep-cleaning"></head><body><main>Our deep cleaning service.</main></body></html>'),
        ]);
        $page = app(TrackedPages::class)->track($this->project, 'https://cleaning.example/old-service?utm_source=search', 'en', 'commercial');

        $payload = $this->payload(['attribution_status' => 'attributed', 'landing_url' => 'https://cleaning.example/old-service?utm_source=google']);
        $this->send($payload)->assertOk()->assertJsonPath('disposition', 'applied');
        $this->send($payload)->assertOk()->assertJsonPath('replayed', true);

        $record = PurchaseRecord::query()->sole();
        $this->assertSame($page->id, $record->site_page_id);
        $this->assertSame('https://cleaning.example/en/services/deep-cleaning', $record->landing_url);
        $this->assertSame('attributed', $record->attribution_status);
        $this->assertSame(1, SitePage::query()->tracked()->count());
        $this->assertSame(1, PurchaseEvent::query()->count());
        $this->assertSame(1, $this->report()['currencies'][0]['attributed_sales']);
    }

    public function test_purchase_windows_preserve_pacific_offsets_at_both_day_boundaries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-15T12:00:00Z'));
        foreach (['2026-09-13', '2026-12-13'] as $day) {
            $from = CarbonImmutable::parse($day, 'America/Los_Angeles');
            $to = $from->addDay();
            foreach ([$from->subSecond(), $from, $to->subSecond(), $to] as $index => $at) {
                $this->send($this->payload(['transaction_id' => $day.':'.$index, 'purchased_at' => $at->toIso8601String()]))->assertOk();
            }
            $report = app(PurchaseReport::class)->summarize($this->source->refresh(), $from, $to);
            $this->assertSame(2, $report['currencies'][0]['completed_sales']);
            $this->assertSame(25000, $report['currencies'][0]['received_minor']);
            $this->assertSame($from->toIso8601String(), $report['from']);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [...[
            'schema_v' => 1, 'event_id' => (string) Str::uuid(), 'transaction_id' => 'cleaning:1', 'revision' => 1,
            'occurred_at' => now()->toIso8601String(), 'purchased_at' => now()->subHour()->toIso8601String(),
            'collection_started_at' => '2026-09-01T00:00:00Z', 'status' => 'paid', 'amount_minor' => 12500, 'refunded_minor' => 0,
            'currency' => 'EUR', 'landing_url' => null, 'attribution_status' => 'unattributed', 'is_new_customer' => null,
            'items' => [['item_id' => 'deep', 'item_name' => 'Deep cleaning', 'quantity' => 1]], 'evidence' => 'ledger:1 payment',
        ], ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function send(array $payload, string $secret = 'test-signing-key', ?int $timestamp = null): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();

        return $this->call('POST', '/api/purchases/'.$this->source->id, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AVYO_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_AVYO_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$raw, $secret),
        ], $raw);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return app(PurchaseReport::class)->summarize($this->source->refresh(), CarbonImmutable::parse('2026-08-20'), CarbonImmutable::parse('2026-09-16'));
    }
}
