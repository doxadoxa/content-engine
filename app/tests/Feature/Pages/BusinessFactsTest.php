<?php

declare(strict_types=1);

namespace Tests\Feature\Pages;

use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\Project;
use App\Models\User;
use App\Pages\BusinessFacts;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BusinessFactsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function confirmation_requires_an_owner_explicit_assertion_source_and_review_date(): void
    {
        [$owner] = $this->owner();
        $this->actingAs($owner)->post('/business-facts', $this->data(['confirm' => null]))->assertSessionHasErrors('confirm');
        $this->post('/business-facts', $this->data(['source_note' => '']))->assertSessionHasErrors('source_note');
        $this->post('/business-facts', $this->data(['review_due_at' => now()->subDay()->toDateString()]))->assertSessionHasErrors('review_due_at');
        $this->assertSame(0, BusinessFact::query()->count());
        $this->post('/business-facts', $this->data())->assertRedirect();
        $version = BusinessFactVersion::query()->sole();
        $this->assertSame($owner->id, $version->confirmed_by);
        $this->assertTrue($version->isUsable());
    }

    #[Test]
    public function drafts_are_not_evidence_and_changing_a_confirmed_fact_creates_an_unconfirmed_version_unless_reconfirmed(): void
    {
        [$owner, $project] = $this->owner();
        $fact = app(BusinessFacts::class)->save($project, $owner, $this->data());
        $original = $fact->currentVersion;
        $this->actingAs($owner)->patch('/business-facts/'.$fact->id, $this->data([
            'statement' => 'Service area now includes Oeiras.', 'status' => 'draft', 'confirm' => null,
            'review_due_at' => null, 'expected_version_id' => $original->id,
        ]))->assertRedirect();
        $fact->refresh();
        $this->assertSame(2, $fact->versions()->count());
        $this->assertFalse($fact->currentVersion->isUsable());
        $this->assertNull($fact->currentVersion->confirmed_at);
        $this->assertSame('We serve Lisbon.', $original->fresh()->statement);
    }

    #[Test]
    public function a_stale_review_cannot_overwrite_a_newer_version(): void
    {
        [$owner, $project] = $this->owner();
        $fact = app(BusinessFacts::class)->save($project, $owner, $this->data());
        $this->actingAs($owner)->patch('/business-facts/'.$fact->id, $this->data(['expected_version_id' => (string) Str::ulid()]))->assertSessionHasErrors('expected_version_id');
        $this->assertSame(1, $fact->versions()->count());
    }

    #[Test]
    public function confirmation_expires_without_becoming_false_and_retraction_preserves_history(): void
    {
        [$owner, $project] = $this->owner();
        $fact = app(BusinessFacts::class)->save($project, $owner, $this->data(['review_due_at' => now()->addDays(2)->toDateString()]));
        $old = $fact->currentVersion;
        $this->travel(3)->days();
        $this->assertFalse($old->isUsable());
        $this->assertSame('confirmed', $old->status);
        $this->actingAs($owner)->patch('/business-facts/'.$fact->id, $this->data([
            'status' => 'retracted', 'confirm' => null, 'review_due_at' => null, 'expected_version_id' => $old->id,
        ]))->assertRedirect();
        $this->assertSame('retracted', $fact->fresh()->currentVersion->status);
        $this->assertSame(2, $fact->versions()->count());
    }

    #[Test]
    public function fact_versions_cannot_be_mutated_in_place(): void
    {
        [$owner, $project] = $this->owner();
        $fact = app(BusinessFacts::class)->save($project, $owner, $this->data());
        $this->expectException(LogicException::class);
        $fact->currentVersion->update(['statement' => 'Invented replacement']);
    }

    #[Test]
    public function another_projects_fact_is_not_readable_or_writable(): void
    {
        [$owner, $project] = $this->owner();
        $fact = app(BusinessFacts::class)->save($project, $owner, $this->data());
        $other = User::factory()->create();
        $other->projects()->attach(Project::factory()->create(), ['role' => 'owner']);
        $this->actingAs($other)->patch('/business-facts/'.$fact->id, $this->data(['expected_version_id' => $fact->current_version_id]))->assertNotFound();
        $this->get('/business-facts')->assertOk()->assertInertia(fn ($page) => $page->has('facts', 0));
    }

    /** @return array{User, Project} */
    private function owner(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create();
        $owner->projects()->attach($project, ['role' => 'owner']);
        app(CurrentProject::class)->set($project);

        return [$owner, $project];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return array_replace(['name' => 'Service area', 'statement' => 'We serve Lisbon.', 'source_url' => 'https://example.com/service-area', 'source_note' => 'Owner-approved current operations coverage.', 'status' => 'confirmed', 'confirm' => '1', 'review_due_at' => now()->addDays(30)->toDateString()], $overrides);
    }
}
