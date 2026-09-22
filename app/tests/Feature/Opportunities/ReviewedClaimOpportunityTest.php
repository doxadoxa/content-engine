<?php

declare(strict_types=1);

namespace Tests\Feature\Opportunities;

use App\Models\BusinessFact;
use App\Models\PageOpportunity;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Opportunities\DiagnoseOpportunities;
use App\Opportunities\RecordClaimOpportunity;
use App\Pages\BusinessFacts;
use App\Pages\TrackedPages;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ReviewedClaimOpportunityTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private SitePage $page;

    private BusinessFact $fact;

    private string $origin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['website_url' => 'https://example.com']);
        app(CurrentProject::class)->set($this->project);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        Http::fake(['*' => Http::response('<html lang="en"><head><title>Service guide</title><link rel="canonical" href="https://example.com/guide"></head><body><main><p>Read our service guide to prepare for a cleaning visit. Confirm the service scope with the team before booking.</p></main><footer>We serve Lisbon and Porto.</footer></body></html>', 200, ['Content-Type' => 'text/html'])]);
        $this->page = app(TrackedPages::class)->track($this->project, 'https://example.com/guide', 'en', 'editorial');
        $this->fact = app(BusinessFacts::class)->save($this->project, $this->owner, $this->factInput());
        $this->origin = (string) Str::ulid();
    }

    public function test_reviewed_footer_claim_on_an_editorial_page_is_pinned_and_survives_automatic_diagnosis(): void
    {
        $opportunity = $this->record();
        $this->assertSame('reviewed_claim', $opportunity->evidence_snapshot['diagnosis_mode']);
        $this->assertSame($this->fact->current_version_id, $opportunity->evidence_snapshot['confirmed_facts'][0]['version_id']);
        $this->assertSame($this->page->latestSnapshot->id, $opportunity->evidence_snapshot['snapshot_id']);
        app(DiagnoseOpportunities::class)->refresh($this->project);
        $this->assertSame('open', $opportunity->refresh()->status);
        $this->assertSame($opportunity->id, $this->record()->id);
        $opportunity->update(['status' => 'dismissed', 'dismissal_reason' => 'The owner requested another check.']);
        $this->assertSame('dismissed', $this->record()->status);
        $this->assertSame(1, PageOpportunity::query()->count());
    }

    public function test_changed_fact_version_cannot_create_a_correction_from_an_old_review(): void
    {
        $prior = $this->fact->currentVersion;
        app(BusinessFacts::class)->save($this->project, $this->owner, [...$this->factInput(), 'statement' => 'We serve Lisbon and Setubal.', 'expected_version_id' => $prior->id], $this->fact);
        $this->expectException(HttpException::class);
        app(RecordClaimOpportunity::class)->record($this->owner, $this->page, $this->page->latestSnapshot, $prior, 'fact_maintenance', $this->origin, $this->evidence());
    }

    public function test_changed_snapshot_and_invented_quote_require_a_new_review(): void
    {
        $old = $this->page->latestSnapshot;
        $this->page = app(TrackedPages::class)->capture($this->project, $this->page);
        try {
            app(RecordClaimOpportunity::class)->record($this->owner, $this->page, $old, $this->fact->currentVersion, 'ai_answer_finding', $this->origin, $this->evidence());
            $this->fail('An old snapshot must not be used.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->expectException(HttpException::class);
        app(RecordClaimOpportunity::class)->record($this->owner, $this->page, $this->page->latestSnapshot, $this->fact->currentVersion, 'ai_answer_finding', $this->origin, [...$this->evidence(), 'owned_page_quote' => 'An invented page quote.']);
    }

    public function test_another_project_or_nonowner_cannot_create_a_correction(): void
    {
        $other = Project::factory()->create();
        app(CurrentProject::class)->set($other);
        $this->owner->projects()->attach($other, ['role' => 'owner']);
        try {
            $this->record();
            $this->fail('Cross-project evidence must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        app(CurrentProject::class)->set($this->project);
        $member = User::factory()->create();
        $member->projects()->attach($this->project, ['role' => 'operator']);
        $this->expectException(HttpException::class);
        app(RecordClaimOpportunity::class)->record($member, $this->page, $this->page->latestSnapshot, $this->fact->currentVersion, 'fact_maintenance', $this->origin, $this->evidence());
    }

    private function record(): PageOpportunity
    {
        return app(RecordClaimOpportunity::class)->record($this->owner, $this->page, $this->page->latestSnapshot, $this->fact->currentVersion, 'fact_maintenance', $this->origin, $this->evidence());
    }

    /** @return array<string, mixed> */
    private function factInput(): array
    {
        return ['name' => 'Service area', 'statement' => 'We serve Lisbon only.', 'source_url' => 'https://example.com/guide', 'source_note' => 'Owner checked the service boundary.', 'status' => 'confirmed', 'confirm' => true, 'review_due_at' => now()->addDays(30)->toDateString()];
    }

    /** @return array<string, mixed> */
    private function evidence(): array
    {
        return ['owned_page_quote' => 'We serve Lisbon and Porto.', 'issue' => 'The footer includes an unsupported service area.', 'reason' => 'The owner confirmed Lisbon only and reviewed this exact footer statement.'];
    }
}
