<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Billing\Entitlements;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\OnboardingStatus;
use App\Enums\PipelineRunStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Onboarding\ProjectLaunch;
use App\Onboarding\WebsiteChecklist;
use App\Pipelines\Events\PipelineRunFinished;
use App\Support\Engine\WorkInFlight;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class FocusedWebsiteLaunchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.enabled' => false]);
    }

    public function test_launch_research_does_not_authorize_social_or_bulk_article_creation(): void
    {
        Queue::fake();
        $project = Project::factory()->onboarding()->create();
        $run = app(ProjectLaunch::class)->begin($project);

        $this->assertSame('research', $run->pipeline);
        $this->assertFalse(PipelineRun::acrossProjects()->where('pipeline', 'content_studio')->exists());

        $run->forceFill(['status' => PipelineRunStatus::Completed, 'finished_at' => now()])->save();
        PipelineRunFinished::dispatch($run);

        $this->assertSame(OnboardingStatus::Active, $project->refresh()->onboarding_status);
        $this->assertFalse(PipelineRun::acrossProjects()->whereIn('pipeline', ['planning', 'generation', 'repurpose'])->exists());
        $this->assertSame(0, ContentItem::acrossProjects()->count());
    }

    public function test_version_two_launch_only_audits_and_guides_existing_page_work(): void
    {
        Queue::fake();
        [, $project] = $this->owner(OnboardingStatus::Draft);
        $this->focusedOffer($project);
        $run = app(ProjectLaunch::class)->begin($project);
        $this->assertNotNull($run);
        $this->assertSame('site_audit', $run->pipeline);
        $this->assertSame(OnboardingStatus::Active, $project->refresh()->onboarding_status);
        $this->assertFalse(PipelineRun::acrossProjects()->whereIn('pipeline', ['research', 'planning', 'generation', 'content_studio'])->exists());
        $this->assertDatabaseCount('content_items', 0);
        $steps = app(CurrentProject::class)->run($project, fn (): array => WebsiteChecklist::for($project));
        $this->assertSame('/pages', collect($steps)->firstWhere('key', 'tracked_page')['action']);
        $this->assertSame('/plan', collect($steps)->firstWhere('key', 'proposal')['action']);
        $this->assertTrue(collect($steps)->firstWhere('key', 'proposal')['locked']);
        $this->assertNull(collect($steps)->firstWhere('key', 'approve'));
    }

    public function test_version_two_without_an_audit_target_still_reaches_usable_setup(): void
    {
        Queue::fake();
        [, $project] = $this->owner(OnboardingStatus::Draft);
        $project->update(['website_url' => null]);
        $this->focusedOffer($project);
        $this->assertNull(app(ProjectLaunch::class)->begin($project));
        $this->assertSame(OnboardingStatus::Active, $project->refresh()->onboarding_status);
        $this->assertDatabaseCount('pipeline_runs', 0);
    }

    public function test_stale_legacy_completion_cannot_start_article_work_on_version_two(): void
    {
        Queue::fake();
        [, $project] = $this->owner(OnboardingStatus::Launching);
        $this->focusedOffer($project);
        $run = app(CurrentProject::class)->run($project, fn (): PipelineRun => PipelineRun::factory()->create(['pipeline' => 'research', 'status' => PipelineRunStatus::Completed, 'finished_at' => now()]));
        app(ProjectLaunch::class)->advance($run);
        $this->assertSame(OnboardingStatus::Active, $project->refresh()->onboarding_status);
        $this->assertDatabaseCount('pipeline_runs', 1);
        $this->assertDatabaseCount('content_items', 0);
    }

    public function test_overview_does_not_count_retired_social_as_work_for_the_owner(): void
    {
        [$user, $project] = $this->owner();
        app(CurrentProject::class)->run($project, function (): void {
            ContentItem::factory()->count(4)->create([
                'type' => ContentItemType::SocialPost,
                'state' => ContentItemState::Draft,
                'channel_type' => 'threads',
            ]);
            ContentItem::factory()->create([
                'type' => ContentItemType::Explainer,
                'state' => ContentItemState::Draft,
                'parent_id' => null,
            ]);
        });

        $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'home/index',
            'X-Inertia-Partial-Data' => 'needs,halves',
        ])->get('/home')->assertOk()
            ->assertJsonPath('props.needs.social_drafts', 0)
            ->assertJsonPath('props.needs.total', 1)
            ->assertJsonPath('props.halves.social', null);

        $this->assertSame(5, ContentItem::acrossProjects()->count(), 'Historical social content is preserved.');
    }

    public function test_setup_uses_website_actions_and_does_not_claim_an_empty_account_has_reviewed_content(): void
    {
        [$user, $project] = $this->owner();
        $steps = app(CurrentProject::class)->run($project, fn (): array => WebsiteChecklist::for($project));

        foreach ($steps as $step) {
            $this->assertFalse(str_starts_with((string) ($step['action'] ?? ''), '/social'));
        }

        $review = collect($steps)->firstWhere('key', 'approve');
        $this->assertNotNull($review);
        $this->assertFalse($review['done']);
        $this->assertTrue($review['locked']);
        $this->actingAs($user)->get('/home')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kinds', [])->where('refusals', null)->etc());
    }

    public function test_onboarding_rejects_social_and_keeps_publication_manual_without_an_article_quota(): void
    {
        [$user, $project] = $this->owner(OnboardingStatus::Draft);
        $this->focusedOffer($project);
        $this->actingAs($user)->postJson('/onboarding/'.$project->id.'/save', [
            'step' => 'channels', 'answers' => ['social' => ['threads']],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.social');

        $this->actingAs($user)->postJson('/onboarding/'.$project->id.'/save', [
            'step' => 'settings', 'answers' => ['target_words' => 1200, 'autopublish' => true],
        ])->assertOk();

        $this->assertFalse($project->refresh()->autopublish);
    }

    public function test_retired_runs_do_not_keep_the_overview_in_setup_or_show_a_social_failure(): void
    {
        [, $project] = $this->owner(OnboardingStatus::Launching);
        app(CurrentProject::class)->run($project, function () use ($project): void {
            PipelineRun::factory()->create(['pipeline' => 'content_studio', 'status' => PipelineRunStatus::Running]);
            PipelineRun::factory()->create([
                'pipeline' => 'social_listen', 'status' => PipelineRunStatus::Failed, 'finished_at' => now(),
            ]);
            $post = ContentItem::factory()->create(['type' => ContentItemType::SocialPost]);
            PipelineRun::factory()->create([
                'pipeline' => 'generation', 'content_item_id' => $post->id,
                'status' => PipelineRunStatus::Failed, 'finished_at' => now(),
            ]);
            PipelineRun::factory()->create([
                'pipeline' => 'generation', 'content_item_id' => null, 'input' => ['content_item_id' => $post->id],
                'status' => PipelineRunStatus::Running,
            ]);
            $website = PipelineRun::factory()->create([
                'pipeline' => 'visibility', 'status' => PipelineRunStatus::Failed, 'finished_at' => now(),
            ]);

            $work = app(WorkInFlight::class)->for($project);
            $this->assertFalse($work['launching']);
            $this->assertSame([], $work['active']);
            $this->assertCount(1, $work['failed']);
            $this->assertSame($website->id, $work['failed'][0]['id']);
            $this->assertSame(5, PipelineRun::query()->count(), 'Retirement preserves historical run records.');
        });
    }

    private function focusedOffer(Project $project): void
    {
        ProjectSubscription::query()->where('project_id', $project->id)->update(['plan' => 'local-search', 'plan_version' => 2]);
        app(Entitlements::class)->forget($project);
    }

    /** @return array{User, Project} */
    private function owner(OnboardingStatus $status = OnboardingStatus::Active): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['onboarding_status' => $status]);
        $user->projects()->attach($project, ['role' => 'owner']);

        return [$user, $project];
    }
}
