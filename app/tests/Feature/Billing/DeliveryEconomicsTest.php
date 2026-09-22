<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\DeliveryEconomics;
use App\Billing\ServiceEffort;
use App\Models\Project;
use App\Models\ServiceEffortEntry;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class DeliveryEconomicsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-15T12:00:00+00:00'));
        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        $this->actingAs($this->owner);
    }

    public function test_unknown_support_is_not_free_and_owner_timer_is_not_labor_cost(): void
    {
        $this->assertNull($this->report()['offer']['remaining_after_recorded_costs_micros']);
        $this->post('/delivery-economics', $this->input(['hourly_usd_cents' => null]))->assertRedirect()->assertSessionHasNoErrors();
        $report = $this->report();
        $this->assertSame(30, $report['support']['unpriced_minutes']);
        $this->assertSame(0, $report['support']['priced_micros']);
        $this->assertNull($report['offer']['remaining_after_recorded_costs_micros']);
        $this->get('/delivery-economics?month=2026-09')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('report.support.unpriced_minutes', 30)->where('report.offer.currency', 'usd'));
    }

    public function test_replayed_save_is_once_and_corrections_replace_counted_time_without_erasing_history(): void
    {
        $input = $this->input();
        $this->post('/delivery-economics', $input)->assertSessionHasNoErrors();
        $this->post('/delivery-economics', $input)->assertSessionHasNoErrors();
        $original = ServiceEffortEntry::query()->sole();
        $this->post('/delivery-economics', [...$input, 'minutes' => 45])->assertStatus(409);
        $this->assertSame(15_000_000, $this->report()['support']['priced_micros']);
        $this->post('/delivery-economics', $this->input(['minutes' => 10, 'supersedes_id' => $original->id, 'note' => 'Corrected the elapsed work log.']))->assertSessionHasNoErrors();
        $this->assertSame(2, ServiceEffortEntry::query()->count());
        $this->assertSame(30, $original->fresh()->minutes);
        $this->assertSame(10, $this->report()['support']['minutes']);
        $this->assertSame(5_000_000, $this->report()['support']['priced_micros']);
        $this->post('/delivery-economics', $this->input(['minutes' => 1, 'supersedes_id' => $original->id]))->assertStatus(409);
        $latest = ServiceEffortEntry::query()->where('supersedes_id', $original->id)->sole();
        $this->post('/delivery-economics', $this->input(['minutes' => 0, 'supersedes_id' => $latest->id, 'note' => 'Void duplicate work.']))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->report()['support']['minutes']);
        $this->assertSame(3, ServiceEffortEntry::query()->count());
    }

    public function test_entry_moved_to_another_month_removes_it_from_the_old_month(): void
    {
        $entry = app(ServiceEffort::class)->record($this->project, $this->owner, $this->input());
        app(ServiceEffort::class)->record($this->project, $this->owner, $this->input(['supersedes_id' => $entry->id, 'happened_at' => '2026-08-31T23:59:00+00:00']));
        $this->assertSame(0, $this->report()['support']['minutes']);
        $august = app(DeliveryEconomics::class)->report($this->project, Carbon::parse('2026-08-01T00:00:00Z'), Carbon::parse('2026-09-01T00:00:00Z'));
        $this->assertSame(30, $august['support']['minutes']);
    }

    public function test_members_cannot_read_costs_or_record_work_and_other_tenant_entries_cannot_be_corrected(): void
    {
        $entry = app(ServiceEffort::class)->record($this->project, $this->owner, $this->input());
        $member = User::factory()->create();
        $member->projects()->attach($this->project, ['role' => 'operator']);
        $this->actingAs($member)->get('/delivery-economics')->assertForbidden();
        $this->post('/delivery-economics', $this->input())->assertForbidden();
        $other = Project::factory()->create();
        $this->owner->projects()->attach($other, ['role' => 'owner']);
        app(CurrentProject::class)->set($other);
        $this->actingAs($this->owner)->withSession([ProjectManager::SESSION_KEY => $other->id])->post('/delivery-economics', $this->input(['supersedes_id' => $entry->id]))->assertNotFound();
        $this->assertSame(0, ServiceEffortEntry::query()->count());
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return app(DeliveryEconomics::class)->report($this->project, Carbon::parse('2026-09-01T00:00:00Z'), Carbon::parse('2026-10-01T00:00:00Z'));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return [...['request_id' => (string) Str::uuid(), 'category' => 'publication', 'minutes' => 30, 'hourly_usd_cents' => 3000, 'happened_at' => '2026-09-14T10:00:00+00:00', 'note' => 'Measured receiver setup and verification.'], ...$overrides];
    }
}
