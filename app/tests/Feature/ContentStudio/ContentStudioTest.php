<?php

declare(strict_types=1);

namespace Tests\Feature\ContentStudio;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Ai\ModelCatalog;
use App\Ai\ModelRequest;
use App\Ai\UnmeteredSession;
use App\ContentStudio\ContentStudioAssistant;
use App\Enums\AssetRole;
use App\Enums\AssetSource;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\ContentPlanStatus;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Enums\PostKind;
use App\Enums\SocialKpi;
use App\Enums\WebhookEvent;
use App\Media\Contracts\ImageGenerationProvider;
use App\Media\FakeImageGeneration;
use App\Models\Asset;
use App\Models\BrandBrief;
use App\Models\ContentGoal;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\ContentPlanMessage;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\User;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\ContentStudioPipeline;
use App\Pipelines\Jobs\RunStepJob;
use App\Publishing\WebhookPayload;
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContentStudioTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Project $project;

    private FakeImageGeneration $images;

    /** Flipped mid-test, so it is a property rather than a captured local. */
    private bool $failsOnTheSecondIdea = false;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-09 10:00:00');

        // Held rather than resolved on demand, so the assertions can read what
        // each channel's picture was actually asked for.
        $this->images = new FakeImageGeneration;
        $this->app->instance(ImageGenerationProvider::class, $this->images);

        $this->operator = User::factory()->create();
        $this->project = Project::factory()->create([
            'name' => 'Persistence',
            'website_url' => 'https://persistance.io',
            'site_analysis' => [
                'name' => 'Persistence',
                'description' => 'A GEO-native content engine for small teams.',
                'audiences' => ['founders', 'content leads'],
                'tone' => 'Direct and practical.',
            ],
            'original_data' => ['four internal projects', 'human review by default'],
        ]);
        $this->operator->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->run($this->project, function (): void {
            BrandBrief::revise($this->project, [
                'positioning' => 'We build a content engine that earns citations.',
                'audience' => 'Founders and content leads.',
                'tone' => 'Direct, specific, no AI hype.',
            ], 'Test brief.');
        });

        $this->actingAs($this->operator)
            ->withSession([ProjectManager::SESSION_KEY => $this->project->getKey()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function studio_starts_with_site_context_and_no_blank_chat_prompt(): void
    {
        $this->get('/social/plan?month=2026-08')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('social/plan')
                ->where('month', '2026-08')
                ->missing('autoPropose')
                ->where('source.website_url', 'https://persistance.io')
                ->where('source.site_description', 'A GEO-native content engine for small teams.')
                ->where('source.has_brief', true)
                ->where('plan', null));
    }

    #[Test]
    public function opening_studio_never_starts_generation_by_itself(): void
    {
        $this->get('/social/plan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('social/plan')
                ->where('month', '2026-08')
                ->missing('autoPropose')
                ->where('plan', null));

        $this->get('/social/plan?month=2026-09')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('social/plan')
                ->where('month', '2026-09')
                ->missing('autoPropose')
                ->where('plan', null));

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(0, ContentPlan::query()->count());
            $this->assertSame(0, PipelineRun::query()->count());
        });
    }

    #[Test]
    public function model_work_is_queued_and_the_http_request_returns_pending_state(): void
    {
        Queue::fake();
        $fake = $this->fakeModel([$this->proposal()]);

        $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->assertStatus(202)
            ->assertJsonPath('plan.version', 0)
            ->assertJsonPath('operation.action', 'proposal')
            ->assertJsonPath('operation.status', 'pending');

        $this->assertSame(0, $fake->callCount());

        app(CurrentProject::class)->run($this->project, function (): void {
            $run = PipelineRun::query()->firstOrFail();
            $step = $run->steps()->firstOrFail();

            $this->assertSame(PipelineRunStatus::Pending, $run->status);
            $this->assertSame(PipelineStepStatus::Pending, $step->status);
            $this->assertSame('apply_content_studio_action', $step->step_key);
        });

        Queue::assertPushed(
            RunStepJob::class,
            static fn (RunStepJob $job): bool => $job->queue === 'pipeline-expensive',
        );
    }

    #[Test]
    public function assistant_proposes_a_structured_month_from_saved_project_context(): void
    {
        $fake = $this->fakeModel([$this->proposal()]);

        $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->assertStatus(202)
            ->assertJsonPath('plan.version', 1)
            ->assertJsonPath('plan.accepted', false)
            ->assertJsonPath('plan.strategy.site_facts.0.source', 'site analysis')
            ->assertJsonCount(4, 'plan.ideas')
            // The fixture asked for all three on every idea. The engine gives
            // each one the two channels its kind is native to — a how_to has
            // nothing to say on Threads that is not better said as an opinion,
            // and an opinion has nothing to photograph.
            ->assertJsonPath('plan.ideas.0.kind', 'how_to')
            ->assertJsonPath('plan.ideas.0.channels', ['instagram', 'x'])
            ->assertJsonPath('plan.ideas.1.kind', 'take')
            ->assertJsonPath('plan.ideas.1.channels', ['threads', 'x'])
            ->assertJsonPath('plan.ideas.2.kind', 'proof')
            ->assertJsonPath('plan.ideas.2.channels', ['instagram', 'threads'])
            // Teaching is the carousel, and it is the carousel on whichever day
            // it falls. This used to be decided by whether the date was even.
            ->assertJsonPath('plan.ideas.0.production.instagram', [
                'format' => 'carousel',
                'visual' => 'slides',
            ])
            ->assertJsonPath('plan.ideas.0.production.x', [
                'format' => 'post_or_thread',
                'visual' => 'image',
            ])
            ->assertJsonPath('plan.ideas.2.production.instagram', [
                'format' => 'image_post',
                'visual' => 'image',
            ])
            ->assertJsonPath('plan.ideas.1.production.threads', [
                'format' => 'post',
                'visual' => 'image',
            ]);

        $this->assertStringContainsString('persistance.io', $fake->lastRequest()->prompt);
        $this->assertStringContainsString('no AI hype', $fake->lastRequest()->prompt);
        $this->assertStringContainsString('four internal projects', $fake->lastRequest()->prompt);

        app(CurrentProject::class)->run($this->project, function (): void {
            $plan = ContentPlan::query()->firstOrFail();
            $run = PipelineRun::query()->firstOrFail();
            $step = $run->steps()->firstOrFail();

            $this->assertSame(1, $plan->assistant_version);
            $this->assertSame(ContentPlanStatus::Draft, $plan->status);
            $this->assertSame(4, ContentIdea::query()->count());
            $this->assertSame(1, ContentPlanMessage::query()->count());
            $this->assertSame('content_studio', $run->pipeline);
            $this->assertSame(PipelineRunStatus::Completed, $run->status);
            $this->assertSame('apply_content_studio_action', $step->step_key);
            $this->assertSame(PipelineStepStatus::Succeeded, $step->status);
            $this->assertSame('outline', $step->role);
            $this->assertGreaterThan(0, $step->input_tokens);
            $this->assertGreaterThan(0, $step->output_tokens);
            $this->assertGreaterThan(0, $step->cost_micros);
            $this->assertSame($step->input_tokens, $run->input_tokens);
            $this->assertSame($step->output_tokens, $run->output_tokens);
            $this->assertSame($step->cost_micros, $run->cost_micros);
            $this->assertSame(
                app(ModelCatalog::class)->cost(
                    FakeModelGateway::MODEL,
                    $step->input_tokens,
                    $step->output_tokens,
                    $run->price_list_version,
                ),
                $step->cost_micros,
            );
            $this->assertSame(
                ['action', 'content_plan_id'],
                array_keys($run->input),
            );
        });

        // React development mode may mount an effect twice. The endpoint is
        // idempotent once the first proposal exists and does not spend twice.
        $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->assertOk()
            ->assertJsonPath('plan.version', 1);

        $this->assertSame(1, $fake->callCount());

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(1, PipelineRun::query()->count());
        });

        $this->get(route('metering.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('by_pipeline.0.pipeline', 'content_studio')
                ->where('by_pipeline.0.runs', 1)
                ->where('by_step.0.pipeline', 'content_studio')
                ->where('by_step.0.step_key', 'apply_content_studio_action')
                ->where('by_step.0.runs', 1)
            );
    }

    #[Test]
    public function a_month_of_nothing_but_tips_is_sent_back_before_it_is_stored(): void
    {
        // Eight ideas rather than the fixture's four, because a target is a
        // percentage of the month and four is too few for most of them to
        // round to one: at that size only `how_to` is asked for at all, so a
        // month of nothing but how-tos is a balanced month and there is
        // nothing here to test.
        $month = ['2026-08-03', '2026-08-04', '2026-08-06', '2026-08-11',
            '2026-08-13', '2026-08-19', '2026-08-21', '2026-08-26'];

        $fake = $this->fakeModel([
            // Nothing but tips: exactly the plan this release was written about.
            $this->proposal(dates: $month, kinds: ['how_to']),
            $this->proposal(dates: $month, kinds: ['how_to', 'take', 'proof', 'behind', 'life']),
        ]);

        $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->assertStatus(202)
            ->assertJsonPath('plan.version', 1);

        $this->assertSame(2, $fake->callCount());
        $this->assertStringContainsString(
            'no take',
            $fake->lastRequest()->prompt,
            'The second ask has to carry what was wrong with the first.',
        );

        app(CurrentProject::class)->run($this->project, function (): void {
            $kinds = ContentIdea::query()->orderBy('scheduled_for')->pluck('kind')->all();

            $this->assertSame(
                [
                    PostKind::HowTo, PostKind::Take, PostKind::Proof, PostKind::Behind,
                    PostKind::Life, PostKind::HowTo, PostKind::Take, PostKind::Proof,
                ],
                $kinds,
            );
        });
    }

    #[Test]
    public function a_month_that_stays_unbalanced_is_proposed_with_the_imbalance_recorded(): void
    {
        // Both asks come back the same. An operator who clicks Propose and gets
        // nothing cannot act; an imbalance they can see, they can refine.
        $month = ['2026-08-03', '2026-08-04', '2026-08-06', '2026-08-11',
            '2026-08-13', '2026-08-19', '2026-08-21', '2026-08-26'];

        $this->fakeModel([
            $this->proposal(dates: $month, kinds: ['how_to']),
            $this->proposal(dates: $month, kinds: ['how_to']),
        ]);

        $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->assertStatus(202)
            ->assertJsonPath('plan.version', 1);

        app(CurrentProject::class)->run($this->project, function (): void {
            $plan = ContentPlan::query()->firstOrFail();
            $findings = $plan->assistant_strategy['mix_findings'] ?? [];

            $this->assertNotSame([], $findings);
            $this->assertStringContainsString('no take', $findings[0]);
            // The register this release added is missing too, and the operator
            // sees that in the same sentence rather than on the next attempt.
            $this->assertStringContainsString('no life', $findings[0]);
            $this->assertSame(8, ContentIdea::query()->count());
        });
    }

    #[Test]
    public function refinement_creates_an_immutable_new_idea_version(): void
    {
        $fake = $this->fakeModel([
            $this->proposal(),
            $this->proposal('A more personal month built around shipping lessons.', 'personal'),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');

        $this->postJson("/studio/plans/{$planId}/refine", [
            'version' => 1,
            'message' => 'Make it more personal and remove launch language.',
        ])->assertStatus(202)
            ->assertJsonPath('plan.version', 2)
            ->assertJsonPath('plan.accepted', false)
            ->assertJsonPath('plan.summary', 'A more personal month built around shipping lessons.')
            ->assertJsonPath('plan.messages.1.role', 'user')
            ->assertJsonPath('plan.messages.2.role', 'assistant');

        $this->assertSame(2, $fake->callCount());

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(8, ContentIdea::query()->count());
            $this->assertSame(4, ContentIdea::query()->where('proposal_version', 1)->count());
            $this->assertSame(4, ContentIdea::query()->where('proposal_version', 2)->count());
        });

        // A stale tab is rejected before another paid model call.
        $this->postJson("/studio/plans/{$planId}/refine", [
            'version' => 1,
            'message' => 'This tab still has the old plan.',
        ])->assertConflict();

        $this->assertSame(2, $fake->callCount());

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(2, PipelineRun::query()->count());
            $this->assertSame(2, PipelineStep::query()->where('step_key', 'apply_content_studio_action')->count());
            $this->assertSame(1, PipelineRun::query()->where('input->action', 'proposal')->count());
            $this->assertSame(1, PipelineRun::query()->where('input->action', 'refine')->count());
        });
    }

    /**
     * The proposal says what the month is *for*, in numbers.
     *
     * The half the engine could not previously produce. A plan could describe
     * twenty ideas in detail and never name a figure, so the Plan screen had
     * nothing on it a person could call optimistic and the operator's own goal
     * form asked them to invent one from a blank field.
     */
    #[Test]
    public function the_proposal_sizes_the_month_and_the_plan_screen_reads_it_back(): void
    {
        // Three posts published in the four weeks before August, so the screen
        // has a real "currently" to put beside the proposed cadence — 0.8 a
        // week, which must not round to 1 and understate the ask.
        $this->publishedBefore(3);

        $this->fakeModel([$this->proposal()]);

        $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->assertStatus(202)
            ->assertJsonPath(
                'plan.strategy.expected_impact',
                'Four posts a week at the current interaction rate reaches 340 by day 28.',
            );

        app(CurrentProject::class)->run($this->project, function (): void {
            $goal = ContentGoal::forMonth(Carbon::parse('2026-08-01'));

            $this->assertNotNull($goal);
            $this->assertSame(SocialKpi::Engagement, $goal->kpi);
            $this->assertSame(340, $goal->target);
            $this->assertSame(4, $goal->cadence);
            $this->assertSame(
                'Find the format that earns a reply',
                $goal->weeks[0]['objective'],
            );
            $this->assertCount(ContentGoal::WEEKS, $goal->weeks);
            // Proposed, not decided. Approving the plan is what confirms it —
            // until then the Overview shows the review step, not a header
            // counting against a number nobody agreed to.
            $this->assertFalse($goal->isConfirmed());
        });

        $this->get('/social/plan?month=2026-08')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('social/plan')
                ->where('goal.target', 340)
                ->where('goal.cadence', 4)
                ->where('goal.confirmed', false)
                // Measured from our own published rows, so it is true on every
                // deployment — unlike the KPI figure below it.
                ->where('goal.current_cadence', 0.8)
                // The target restated as a rate, which is the figure that makes
                // it judgeable: 340 is an abstraction, 85 a week is not.
                ->where('goal.per_week_needed', 85)
                // Nothing in this engine reads an interaction back, so the
                // progress figure is absent rather than a confident zero.
                ->where('goal.progress', null)
                ->whereNot('goal.needs', null)
                // The form that used to live on the Overview, offered here
                // beside the argument for the numbers it is arguing with.
                ->has('kpis', 3)
                ->etc()
            );
    }

    #[Test]
    public function approving_the_plan_confirms_the_goal_it_was_written_against(): void
    {
        $this->fakeModel([$this->proposal()]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');

        // One click, both rows. The two were separate buttons on separate
        // screens, which produced an accepted plan above an unconfirmed goal —
        // a month underway that the Overview still called "no goal set".
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])
            ->assertOk()
            ->assertJsonPath('plan.accepted', true)
            ->assertJsonPath('goal.confirmed', true);

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertTrue(
                ContentGoal::forMonth(Carbon::parse('2026-08-01'))?->isConfirmed(),
            );
        });

        $this->get('/social?month=2026-08')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('goal.confirmed', true)
                // Four weeks of the proposed cadence, which is now the
                // denominator the board reports against.
                ->where('progress.planned', 16)
                ->etc()
            );
    }

    /**
     * A decided goal outlives every proposal made against it.
     *
     * The rule the goals table was split off `content_plans` to make possible.
     * Refinement replaces the whole proposal, so a goal stored with it would
     * mean that asking for better *ideas* silently rewrote what the month was
     * *for* — and the assistant would be moving the target it is being measured
     * against, which is no target at all.
     */
    #[Test]
    public function a_confirmed_goal_survives_a_refinement_that_proposes_different_numbers(): void
    {
        $this->fakeModel([
            $this->proposal(),
            $this->proposal(goal: [
                'kpi' => 'followers',
                'target' => 9999,
                'cadence' => 7,
                'expected_impact' => 'A far more exciting month.',
                'weeks' => ['One', 'Two', 'Three', 'Four'],
            ]),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');

        $this->post('/social/goal', [
            'month' => '2026-08',
            'kpi' => 'reach',
            'target' => 2500,
            'cadence' => 4,
        ])->assertRedirect();

        $this->postJson("/studio/plans/{$planId}/refine", [
            'version' => 1,
            'message' => 'Make it more personal.',
        ])->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $goal = ContentGoal::forMonth(Carbon::parse('2026-08-01'));

            $this->assertNotNull($goal);
            $this->assertSame(SocialKpi::Reach, $goal->kpi);
            $this->assertSame(2500, $goal->target);
            $this->assertTrue($goal->isConfirmed());
        });
    }

    /**
     * A month the model failed to size is still a month worth having.
     *
     * Deliberately not filled in with a default. An invented target renders
     * identically to a reasoned one, and the operator approves the month against
     * it — which is precisely the failure that moving this off the blank form
     * was meant to end. So the goal is absent, the twenty ideas are not, and the
     * screen says which.
     */
    #[Test]
    public function a_proposal_that_names_no_usable_numbers_leaves_the_month_unsized(): void
    {
        $this->fakeModel([$this->proposal(goal: ['kpi' => 'vibes', 'target' => 0, 'cadence' => 900])]);

        $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->assertStatus(202)
            ->assertJsonCount(4, 'plan.ideas');

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertNull(ContentGoal::forMonth(Carbon::parse('2026-08-01')));
        });

        $this->get('/social/plan?month=2026-08')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('goal', null)
                ->etc()
            );
    }

    #[Test]
    public function accepting_an_assistant_version_does_not_approve_hidden_seo_work(): void
    {
        $this->fakeModel([$this->proposal()]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');

        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])
            ->assertOk()
            ->assertJsonPath('plan.accepted', true)
            ->assertJsonPath('plan.accepted_version', 1);

        app(CurrentProject::class)->run($this->project, function (): void {
            $plan = ContentPlan::query()->firstOrFail();

            $this->assertSame(ContentPlanStatus::Draft, $plan->status);
            $this->assertSame(1, $plan->assistant_accepted_version);
        });
    }

    #[Test]
    public function onboarding_can_generate_one_preview_idea_before_plan_acceptance(): void
    {
        $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');

        app(CurrentProject::class)->run($this->project, function () use ($planId): void {
            app(PipelineRunner::class)->start(
                ContentStudioPipeline::key(),
                $this->project,
                [
                    'action' => 'generate_week',
                    'content_plan_id' => $planId,
                    'initial' => true,
                ],
            );

            $plan = ContentPlan::query()->whereKey($planId)->firstOrFail();
            $items = ContentItem::query()->with('assets')->get();

            $this->assertFalse($plan->hasAcceptedAssistantVersion());
            // Two, not three: the preview idea is a how_to, and teaching goes
            // to Instagram and X. An idea that reached all three channels was
            // the cross-posting this release stopped producing.
            $this->assertCount(2, $items);
            $this->assertTrue($items->every(
                static fn (ContentItem $item): bool => $item->assets->count() === 1,
            ));
            $this->assertTrue($items->every(
                static fn (ContentItem $item): bool => isset($item->channel_payload['asset_id']),
            ));
        });
    }

    #[Test]
    public function generation_stops_at_native_reviewable_drafts_for_the_next_week(): void
    {
        $this->fakeStudio([
            $this->proposal(),
            $this->proposal('The remaining month now focuses on operator lessons.'),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');

        $this->postJson("/studio/plans/{$planId}/generate")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Accept the current proposal before generating drafts.');

        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();

        $this->postJson("/studio/plans/{$planId}/generate")
            ->assertStatus(202)
            // The fan-out reports what it dispatched, not what the drafting
            // produced — the drafts are written by a run per idea now.
            ->assertJsonPath('operation.result.ideas', 2)
            // A plan-level action is about no single idea, so the card-level
            // "Writing…" state must not latch onto it.
            ->assertJsonPath('operation.idea_id', null)
            ->assertJsonPath('operation.result.from', '2026-08-03')
            ->assertJsonPath('operation.result.until', '2026-08-09')
            ->assertJsonCount(2, 'plan.ideas.0.drafts')
            ->assertJsonCount(1, 'plan.ideas.0.drafts.0.assets')
            ->assertJsonCount(2, 'plan.ideas.1.drafts')
            ->assertJsonCount(0, 'plan.ideas.2.drafts');

        app(CurrentProject::class)->run($this->project, function (): void {
            $items = ContentItem::query()->orderBy('channel_type')->get();

            $this->assertCount(4, $items);
            $this->assertSame(
                ['instagram', 'threads', 'x', 'x'],
                $items->pluck('channel_type')->all(),
            );
            $this->assertTrue($items->every(
                static fn (ContentItem $item): bool => $item->state === ContentItemState::Draft,
            ));
            $this->assertSame('carousel', $items->first()->channel_payload['format']);
            $this->assertNull($items->first()->published_at);
            $this->assertStringStartsWith('2026-08-', $items->first()->slug);
        });

        // Refining after week one does not hide or duplicate work already
        // written. The drafted idea is frozen into v2, while the unwritten
        // remainder can move.
        $this->postJson("/studio/plans/{$planId}/refine", [
            'version' => 1,
            'message' => 'Keep week one and make the rest more operational.',
        ])->assertStatus(202)
            ->assertJsonPath('plan.version', 2)
            ->assertJsonCount(2, 'plan.ideas.0.drafts');

        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 2])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")
            ->assertStatus(202)
            ->assertJsonPath('operation.result.ideas', 1)
            ->assertJsonCount(2, 'plan.ideas.0.drafts')
            ->assertJsonCount(2, 'plan.ideas.1.drafts');

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(6, ContentItem::query()->count());  // 4 + the refined week's 2
            // Two proposals, two fan-outs, and a run for each idea they
            // dispatched: seven. A run per idea is the point — the count going
            // up is what the deadline going down bought.
            $this->assertSame(7, PipelineRun::query()->count());
            $this->assertSame(2, PipelineRun::query()->where('input->action', 'generate_week')->count());
            $this->assertSame(3, PipelineRun::query()->where('input->action', 'generate_idea')->count());
            $this->assertSame(
                'carousel',
                ContentItem::query()
                    ->where('channel_type', 'instagram')
                    ->whereDate('scheduled_for', '2026-08-03')
                    ->firstOrFail()
                    ->channel_payload['format'],
            );
        });
    }

    /**
     * The Studio's smallest unit of work is one idea, not one week.
     *
     * And deliberately not gated on acceptance, unlike the weekly button. The
     * gate exists so a model's month does not become drafts without a person
     * saying it is the right month; pressing Create on one idea *is* that
     * person saying so, about the only idea it will spend money on. The
     * assertions below pin both halves — that the plan is unaccepted, and that
     * exactly one idea got written.
     */
    #[Test]
    public function one_idea_can_be_written_on_its_own_without_a_week_or_an_acceptance(): void
    {
        $this->fakeStudio();

        $plan = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan');
        $ideaId = $plan['ideas'][2]['id'];

        $this->postJson("/studio/ideas/{$ideaId}/generate")
            ->assertStatus(202)
            ->assertJsonPath('operation.action', 'generate_idea')
            // Which idea, so the card that was pressed can say it is the one
            // being written rather than leaving the operator to read a banner
            // about the plan and guess.
            ->assertJsonPath('operation.idea_id', $ideaId)
            ->assertJsonPath('plan.accepted', false)
            // The third idea is a `proof`, which goes to Instagram and Threads.
            ->assertJsonCount(2, 'plan.ideas.2.drafts')
            ->assertJsonCount(0, 'plan.ideas.0.drafts')
            ->assertJsonCount(0, 'plan.ideas.1.drafts')
            ->assertJsonCount(0, 'plan.ideas.3.drafts');

        app(CurrentProject::class)->run($this->project, function () use ($ideaId): void {
            $items = ContentItem::query()->orderBy('channel_type')->get();

            $this->assertCount(2, $items);
            $this->assertSame(['instagram', 'threads'], $items->pluck('channel_type')->all());
            $this->assertTrue($items->every(
                static fn (ContentItem $item): bool => (string) $item->content_idea_id === $ideaId,
            ));
            $this->assertTrue($items->every(
                static fn (ContentItem $item): bool => $item->state === ContentItemState::Draft,
            ));

            // One idea run and no fan-out: this door does not go through the
            // week. The proposal accounts for the second run.
            $this->assertSame(0, PipelineRun::query()->where('input->action', 'generate_week')->count());
            $this->assertSame(1, PipelineRun::query()->where('input->action', 'generate_idea')->count());
            $this->assertSame(
                $ideaId,
                PipelineRun::query()->where('input->action', 'generate_idea')->firstOrFail()
                    ->input['content_idea_id'],
            );
        });
    }

    /**
     * A finished idea is refused here rather than dispatched to discover it.
     *
     * `generateIdea()` returns `created: 0` for an idea whose channels all have
     * drafts, which is correct for a retry and useless as an answer to a
     * person: they would watch a worker pick the run up, spend a slot, and
     * report that nothing happened.
     */
    #[Test]
    public function an_idea_that_is_already_written_is_refused_rather_than_redrafted(): void
    {
        $this->fakeStudio();

        $plan = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan');
        $ideaId = $plan['ideas'][2]['id'];

        $this->postJson("/studio/ideas/{$ideaId}/generate")->assertStatus(202);

        $before = app(CurrentProject::class)->run(
            $this->project,
            static fn (): int => PipelineRun::query()->count(),
        );

        $this->postJson("/studio/ideas/{$ideaId}/generate")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Every channel of this idea is already drafted.');

        app(CurrentProject::class)->run($this->project, function () use ($before): void {
            $this->assertSame($before, PipelineRun::query()->count());
            $this->assertSame(2, ContentItem::query()->count());
        });
    }

    /**
     * A partly written idea finishes rather than starting over.
     *
     * The same idempotence the weekly path relies on, reached through the new
     * door: a channel that already has a draft is skipped, so pressing Create
     * again after one channel failed writes the missing one and leaves the
     * other alone.
     */
    #[Test]
    public function creating_a_partly_written_idea_writes_only_its_missing_channels(): void
    {
        $this->fakeStudio();

        $plan = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan');
        $ideaId = $plan['ideas'][2]['id'];

        $this->postJson("/studio/ideas/{$ideaId}/generate")->assertStatus(202);

        $kept = app(CurrentProject::class)->run($this->project, function () use ($ideaId): string {
            $threads = ContentItem::query()
                ->where('content_idea_id', $ideaId)
                ->where('channel_type', 'threads')
                ->firstOrFail();

            // As though Instagram had failed and Threads had not.
            ContentItem::query()
                ->where('content_idea_id', $ideaId)
                ->where('channel_type', 'instagram')
                ->delete();

            return (string) $threads->getKey();
        });

        $this->postJson("/studio/ideas/{$ideaId}/generate")
            ->assertStatus(202)
            ->assertJsonCount(2, 'plan.ideas.2.drafts');

        app(CurrentProject::class)->run($this->project, function () use ($ideaId, $kept): void {
            $items = ContentItem::query()->where('content_idea_id', $ideaId)->get();

            $this->assertCount(2, $items);
            $this->assertTrue($items->contains(
                static fn (ContentItem $item): bool => (string) $item->getKey() === $kept,
            ));
        });
    }

    #[Test]
    public function another_projects_idea_is_not_addressable_through_studio_routes(): void
    {
        $other = Project::factory()->create();

        $idea = app(CurrentProject::class)->run($other, static function (): ContentIdea {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create();

            return $plan->contentIdeas()->create([
                'proposal_version' => 1,
                'idea_key' => 'somebody-elses-idea',
                'title' => 'Somebody else’s idea',
                'kind' => PostKind::Take,
                'pillar' => 'Theirs',
                'thesis' => 'It belongs to another tenant.',
                'evidence' => [],
                'goal' => 'trust',
                'audience' => 'theirs',
                'angle' => null,
                'channels' => ['threads', 'x'],
                'scheduled_for' => '2026-08-03',
            ]);
        });

        $this->postJson("/studio/ideas/{$idea->getKey()}/generate")
            ->assertNotFound();
    }

    #[Test]
    public function unexpected_provider_errors_are_not_exposed_to_the_browser(): void
    {
        $fake = (new FakeModelGateway)->willThrow(
            static fn (): \RuntimeException => new \RuntimeException('secret upstream details'),
        );
        $this->app->instance(ModelGateway::class, $fake);

        /** @var list<array<string, mixed>> $logged */
        $logged = [];

        Log::listen(function (MessageLogged $event) use (&$logged): void {
            if ($event->message === 'Pipeline step failed') {
                $logged[] = $event->context;
            }
        });

        $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->assertStatus(202)
            ->assertJsonPath('operation.status', 'failed')
            ->assertJsonPath('operation.message', 'The assistant could not build a proposal right now.')
            ->assertJsonMissing(['message' => 'secret upstream details']);

        app(CurrentProject::class)->run($this->project, function (): void {
            $run = PipelineRun::query()->firstOrFail();
            $step = $run->steps()->firstOrFail();

            $this->assertSame(PipelineRunStatus::Failed, $run->status);
            $this->assertSame('apply_content_studio_action', $run->failed_step_key);
            $this->assertSame(PipelineStepStatus::Failed, $step->status);
            $this->assertSame(0, $step->input_tokens);
            $this->assertSame(0, $step->output_tokens);
            $this->assertNull($step->output);
            $this->assertSame('The Content Studio model action failed.', $run->error['message']);
            $this->assertStringNotContainsString(
                'secret upstream details',
                (string) json_encode([$run->error, $step->error]),
            );
        });

        // Withheld from the customer, not thrown away. The operator's copy is
        // the log line, and it is the only place the provider's own words
        // survive — which is why the first five of these failures could not be
        // diagnosed at all.
        $this->assertNotEmpty($logged);
        $this->assertStringContainsString(
            'secret upstream details',
            (string) json_encode($logged[0]['caused_by'] ?? []),
        );
    }

    #[Test]
    public function tokens_spent_before_invalid_model_output_are_still_metered(): void
    {
        $this->fakeModel(['not structured json']);

        $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->assertStatus(202)
            ->assertJsonPath('operation.status', 'failed')
            ->assertJsonPath('operation.message', 'The assistant did not return a structured monthly proposal.');

        app(CurrentProject::class)->run($this->project, function (): void {
            $run = PipelineRun::query()->firstOrFail();
            $step = $run->steps()->firstOrFail();

            $this->assertSame(PipelineRunStatus::Failed, $run->status);
            $this->assertSame(PipelineStepStatus::Failed, $step->status);
            $this->assertGreaterThan(0, $step->input_tokens);
            $this->assertGreaterThan(0, $step->output_tokens);
            $this->assertGreaterThan(0, $step->cost_micros);
            $this->assertNull($step->output);
            $this->assertSame($step->cost_micros, $run->cost_micros);
            $this->assertSame(0, ContentIdea::query()->count());
        });
    }

    #[Test]
    public function invalid_channel_copy_is_rejected_and_corrected_instead_of_truncated(): void
    {
        $overLong = 0;
        $corrections = [];

        $fake = $this->fakeStudio(draft: function (ModelRequest $request) use (&$overLong, &$corrections): ?string {
            if ($this->channelOf($request) !== 'x') {
                return null;
            }

            if (str_contains($request->prompt, 'Your previous answer was invalid')) {
                $corrections[] = $request->prompt;

                return null;
            }

            // Only the first X candidate comes back over the limit, so the run
            // shows a correction happening without every candidate needing one.
            if ($overLong++ > 0) {
                return null;
            }

            return $this->candidate($request, str_repeat('x', 281));
        });

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")
            ->assertStatus(202)
            ->assertJsonPath('operation.result.ideas', 2);

        // The over-long candidate was sent back with the reason rather than cut
        // to fit: a truncated post is a post that stops mid-sentence.
        $this->assertCount(1, $corrections);
        $this->assertStringContainsString('exceeds 280 characters', $corrections[0]);
        $this->assertGreaterThan(3, $fake->callCount());

        app(CurrentProject::class)->run($this->project, function (): void {
            $x = ContentItem::query()->where('channel_type', 'x')->firstOrFail();
            $this->assertSame('A compact X post.', $x->channel_payload['segments'][0]['text']);
            $this->assertSame([], $x->channel_payload['guard_findings']);

            // The tokens are on the idea's run, not the fan-out's. Dispatching
            // spends nothing, and §6's per-unit cost is a sum of step rows —
            // so the rows have to be where the spending happened.
            $this->assertSame(
                0,
                (int) PipelineRun::query()->where('input->action', 'generate_week')->firstOrFail()->cost_micros,
            );

            foreach (PipelineRun::query()->where('input->action', 'generate_idea')->get() as $run) {
                $this->assertGreaterThan(0, $run->steps()->firstOrFail()->cost_micros);
            }
        });
    }

    #[Test]
    public function each_channel_is_written_separately_against_its_own_rules(): void
    {
        $fake = $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $drafts = array_values(array_filter(
            $fake->sent(),
            static fn (ModelRequest $request): bool => $request->role === 'draft',
        ));

        // Four channel slots across the window's two ideas — a how_to on
        // Instagram and X, a take on Threads and X — four candidates each. One
        // call for every channel at once is what produced the paraphrases.
        $this->assertCount(16, $drafts);

        $byChannel = [];

        foreach ($drafts as $request) {
            $byChannel[$this->channelOf($request)][] = $request;
        }

        $this->assertEqualsCanonicalizing(['threads', 'x', 'instagram'], array_keys($byChannel));
        $this->assertCount(4, $byChannel['threads']);
        $this->assertCount(8, $byChannel['x']);
        $this->assertCount(4, $byChannel['instagram']);

        // Each channel is told its own rules, and they are not the same rules.
        $this->assertStringContainsString('No hashtags at all.', $byChannel['threads'][0]->instructions);
        $this->assertStringContainsString('One point per post.', $byChannel['x'][0]->instructions);
        $this->assertStringContainsString(
            'The first line is the hook',
            $byChannel['instagram'][0]->instructions,
        );

        // And the four calls of a pool ask for four different shapes, which is
        // the only reason a pool is worth ranking.
        $shapes = array_map(
            static fn (ModelRequest $request): string => $request->prompt,
            $byChannel['threads'],
        );

        // Three rather than four: the channel's list is narrowed to the shapes
        // the idea's kind can actually take, so an opinion post never spends a
        // quarter of its pool writing a photo caption.
        $this->assertCount(3, array_unique($shapes));

        app(CurrentProject::class)->run($this->project, function (): void {
            $threads = ContentItem::query()->where('channel_type', 'threads')->firstOrFail();
            $selection = $threads->channel_payload['selection'];

            $this->assertSame(4, $selection['pool']);
            $this->assertSame(50, $selection['bar']);
            $this->assertTrue($selection['cleared_bar']);
            $this->assertContains($selection['angle'], ['question', 'take', 'observation', 'caption']);
            $this->assertCount(4, $selection['scores']);
        });
    }

    #[Test]
    public function a_candidate_that_could_not_be_published_loses_to_one_that_could(): void
    {
        $written = 0;

        $this->fakeStudio(draft: function (ModelRequest $request) use (&$written): ?string {
            if ($this->channelOf($request) !== 'threads') {
                return null;
            }

            // A bare link scores well on nothing and the guard refuses it
            // outright; it is also the first candidate written, so only the
            // ranking can keep it out of the draft.
            return $written++ === 0
                ? $this->candidate($request, 'https://persistance.io/blog/one')
                : null;
        });

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $threads = ContentItem::query()->where('channel_type', 'threads')->firstOrFail();

            $this->assertSame(
                'What should a content pipeline explain before you trust it?',
                $threads->channel_payload['segments'][0]['text'],
            );
            $this->assertSame([], $threads->channel_payload['guard_findings']);

            // The bare link is still in the pool and still scored — it lost on
            // the ranking rather than being quietly dropped, which is what
            // makes "the best of four" an honest sentence.
            $scores = $threads->channel_payload['selection']['scores'];

            $this->assertCount(4, $scores);
            $this->assertLessThan(50, min($scores));
        });
    }

    #[Test]
    public function a_draft_nothing_could_save_ships_with_its_refusals_attached(): void
    {
        // Every candidate is a bare link, so there is no clean one to prefer.
        $this->fakeStudio(draft: fn (ModelRequest $request): ?string => $this->channelOf($request) === 'threads'
            ? $this->candidate($request, 'https://persistance.io/blog/one')
            : null);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $threads = ContentItem::query()->where('channel_type', 'threads')->firstOrFail();
            $findings = $threads->channel_payload['guard_findings'];

            // The draft still exists — the Studio is a review surface, and an
            // operator shown an empty slot can only re-run and hope — but it
            // does not arrive looking like a post that passed.
            $this->assertSame(ContentItemState::Draft, $threads->state);
            $this->assertNotSame([], $findings);
            $this->assertContains(
                'bare_link',
                array_column($findings, 'code'),
            );
            $this->assertFalse($threads->channel_payload['selection']['cleared_bar']);
        });
    }

    #[Test]
    public function an_ordinary_project_is_not_fact_checked(): void
    {
        $fake = $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $this->assertSame(
            0,
            count(array_filter(
                $fake->sent(),
                static fn (ModelRequest $request): bool => $request->role === 'factcheck',
            )),
            '§10 names one kind of project, and a check nobody needs is money spent on latency.',
        );

        app(CurrentProject::class)->run($this->project, function (): void {
            $threads = ContentItem::query()->where('channel_type', 'threads')->firstOrFail();

            $this->assertArrayNotHasKey('fact_check', $threads->channel_payload);
        });
    }

    #[Test]
    public function an_invented_figure_is_recorded_against_the_draft_on_a_ymyl_project(): void
    {
        $this->project->forceFill(['is_ymyl' => true])->save();

        $this->fakeStudio(
            factCheck: static fn (ModelRequest $request): ?string => str_contains($request->prompt, 'content pipeline')
                ? 'The post claims a figure that is not in the supplied facts.'
                : null,
        );

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $threads = ContentItem::query()->where('channel_type', 'threads')->firstOrFail();
            $x = ContentItem::query()->where('channel_type', 'x')->firstOrFail();

            $this->assertFalse($threads->channel_payload['fact_check']['passed']);
            $this->assertSame(
                ['The post claims a figure that is not in the supplied facts.'],
                $threads->channel_payload['fact_check']['findings'],
            );
            $this->assertTrue($x->channel_payload['fact_check']['passed']);
        });
    }

    #[Test]
    public function a_fact_check_pass_does_not_promote_a_post_the_guard_refused(): void
    {
        $this->project->forceFill(['is_ymyl' => true])->save();

        $written = 0;

        $this->fakeStudio(
            // The clean candidates all state a figure; the bare link states
            // nothing and so passes every fact check trivially. Walking past
            // the clean group to find a pass would publish the bare link.
            draft: function (ModelRequest $request) use (&$written): ?string {
                if ($this->channelOf($request) !== 'threads') {
                    return null;
                }

                return $written++ === 3
                    ? $this->candidate($request, 'https://persistance.io/blog/one')
                    : $this->candidate($request, 'We cut 40% of the planner. What would you have cut?');
            },
            factCheck: static fn (ModelRequest $request): ?string => str_contains($request->prompt, '40%')
                ? 'The 40% figure is not in the supplied facts.'
                : null,
        );

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $threads = ContentItem::query()->where('channel_type', 'threads')->firstOrFail();

            $this->assertStringContainsString('40%', (string) $threads->body_markdown);
            $this->assertFalse($threads->channel_payload['fact_check']['passed']);
            $this->assertSame([], $threads->channel_payload['guard_findings']);
        });
    }

    #[Test]
    public function a_carousel_slide_is_checked_like_the_caption_is(): void
    {
        app(CurrentProject::class)->run($this->project, function (): void {
            BrandBrief::revise($this->project, ['forbidden_topics' => ['refunds']], 'No refunds talk.');
        });

        // An even day, because that is what makes the idea a carousel — see
        // ContentIdea::instagramFormat().
        $this->fakeStudio(answers: [
            $this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26']),
        ], draft: function (ModelRequest $request): ?string {
            if ($this->channelOf($request) !== 'instagram') {
                return null;
            }

            return (string) json_encode([
                'caption' => 'A trustworthy content engine shows its work.',
                'slides' => [
                    ['heading' => 'The idea', 'body' => 'Decisions should remain inspectable.'],
                    // The caption is clean; the panel is not. Nothing used to
                    // read this text at all.
                    ['heading' => 'The catch', 'body' => 'Ask us about refunds if it does not work.'],
                ],
                'visual' => $this->visual(),
            ]);
        });

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $instagram = ContentItem::query()->where('channel_type', 'instagram')->firstOrFail();

            $this->assertContains(
                'forbidden_topic',
                array_column($instagram->channel_payload['guard_findings'], 'code'),
            );
        });
    }

    #[Test]
    public function a_link_the_post_was_scored_for_carrying_is_the_link_it_ships_with(): void
    {
        $this->fakeStudio(draft: fn (ModelRequest $request): ?string => $this->channelOf($request) === 'threads'
            ? (string) json_encode([
                'segments' => ['Our teardown of the pricing change. Does this match what you saw?'],
                'link' => 'https://persistance.io/blog/pricing',
                'visual' => $this->visual(),
            ])
            : null);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $threads = ContentItem::query()->where('channel_type', 'threads')->firstOrFail();

            // The name a publisher reads. Parsed, scored against and guarded,
            // and then dropped on the way to storage until this was fixed.
            $this->assertSame(
                'https://persistance.io/blog/pricing',
                $threads->channel_payload['link_attachment'],
            );
        });
    }

    #[Test]
    public function an_idea_that_will_not_draft_does_not_take_the_week_with_it(): void
    {
        $this->fakeStudio(
            answers: [$this->proposal(dates: ['2026-08-03', '2026-08-04', '2026-08-19', '2026-08-26'])],
            draft: function (ModelRequest $request): ?string {
                if (str_contains($request->instructions, 'Content idea 2')) {
                    throw new \RuntimeException('the provider refused');
                }

                return null;
            },
        );

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();

        // The fan-out itself succeeds: dispatching is not drafting.
        $this->postJson("/studio/plans/{$planId}/generate")
            ->assertStatus(202)
            ->assertJsonPath('operation.result.ideas', 2);

        app(CurrentProject::class)->run($this->project, function (): void {
            // One idea drafted, one idea's run failed, and the failure is a run
            // of its own rather than the end of the week. Under the old shape
            // both ideas lived in one job and the second one's provider error
            // ended the batch.
            $this->assertSame(2, ContentItem::query()->count());

            $runs = PipelineRun::query()->where('input->action', 'generate_idea')->get();

            $this->assertCount(2, $runs);
            $this->assertSame(1, $runs->where('status', PipelineRunStatus::Completed)->count());
            $this->assertSame(1, $runs->where('status', PipelineRunStatus::Failed)->count());
        });
    }

    #[Test]
    public function the_second_channel_of_an_idea_is_shown_what_the_first_already_said(): void
    {
        $prompts = [];

        $this->fakeStudio(draft: function (ModelRequest $request) use (&$prompts): ?string {
            $prompts[$this->channelOf($request)][] = $request->prompt;

            return null;
        });

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        // The first channel of an idea has nothing to avoid repeating.
        $this->assertStringNotContainsString(
            'already been written for another channel',
            $prompts['instagram'][0],
        );

        // The second does, and it is given the text rather than told to differ.
        // Separate calls alone do not stop two channels arriving at the same
        // sentence: one thesis and one angle converge.
        // X is the second channel of the first idea, a how_to, whose first
        // channel is Instagram.
        $later = $prompts['x'][0];

        $this->assertStringContainsString('already been written for another channel', $later);
        $this->assertStringContainsString('A trustworthy content engine shows its work.', $later);
    }

    #[Test]
    public function two_channels_of_one_idea_never_take_the_same_shape(): void
    {
        $angles = [];

        $this->fakeStudio(draft: function (ModelRequest $request) use (&$angles): ?string {
            $angles[$this->channelOf($request)][] = $request->prompt;

            return null;
        });

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $byIdea = ContentItem::query()
                ->where('type', ContentItemType::SocialPost)
                ->get()
                ->groupBy('content_idea_id');

            $this->assertGreaterThan(1, $byIdea->count());

            foreach ($byIdea as $drafts) {
                $taken = $drafts
                    ->map(static fn (ContentItem $item): string => (string) ($item->channel_payload['angle'] ?? ''))
                    ->all();

                // The ranker pays 18 points for a question mark, so without
                // this the question-shaped candidate won every channel of an
                // idea and two platforms carried the same sentence.
                $this->assertSame(
                    count($taken),
                    count(array_unique($taken)),
                    'Two channels of one idea took the same shape: '.implode(', ', $taken),
                );
            }
        });
    }

    #[Test]
    public function retrying_one_idea_finishes_that_idea_rather_than_the_week(): void
    {
        $this->failsOnTheSecondIdea = true;

        $fake = $this->fakeStudio(
            answers: [$this->proposal(dates: ['2026-08-03', '2026-08-04', '2026-08-19', '2026-08-26'])],
            draft: function (ModelRequest $request): ?string {
                if ($this->failsOnTheSecondIdea
                    && str_contains($request->instructions, 'Content idea 2')) {
                    throw new \RuntimeException('the worker went away');
                }

                return null;
            },
        );

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $failed = app(CurrentProject::class)->run($this->project, static fn (): PipelineRun => PipelineRun::query()
            ->where('input->action', 'generate_idea')
            ->where('status', PipelineRunStatus::Failed)
            ->firstOrFail());

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(2, ContentItem::query()->count());
        });

        // The same run, retried — the pipeline retries within a run, so the id
        // is unchanged. It has one idea to finish, not a week to redo.
        $this->failsOnTheSecondIdea = false;
        $fake->willAnswer([]);

        app(CurrentProject::class)->run($this->project, function () use ($failed): void {
            $idea = ContentIdea::query()->whereKey((string) $failed->input['content_idea_id'])->firstOrFail();

            $result = app(ContentStudioAssistant::class)->generateIdea(
                $idea,
                app(UnmeteredSession::class),
                (string) $failed->getKey(),
            );

            $this->assertSame(2, $result['created']);
            $this->assertSame(4, ContentItem::query()->count());
        });
    }

    #[Test]
    public function a_teaching_carousel_gets_a_drawn_panel_for_every_slide(): void
    {
        Http::fake(['*/render' => Http::response($this->png(), 200, [
            'Content-Type' => 'image/png',
        ])]);
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        // An even day, which is what makes the idea a carousel.
        $this->fakeStudio(answers: [
            $this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26']),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $carousel = ContentItem::query()
                ->where('type', ContentItemType::SocialPost)
                ->where('channel_type', 'instagram')
                ->firstOrFail();

            $panels = Asset::query()
                ->where('content_item_id', $carousel->getKey())
                ->where('role', AssetRole::Inline)
                ->orderBy('anchor')
                ->get();

            // The two slides the fixture writes, each its own picture. Until
            // this existed the steps were caption text under one photograph.
            $this->assertCount(2, $panels);
            $this->assertSame(['slide-01', 'slide-02'], $panels->pluck('anchor')->all());
            $this->assertSame('The idea', $panels->first()->alt);
            $this->assertSame(AssetSource::Rendered, $panels->first()->source);
            $this->assertSame(1080, $panels->first()->width);
            $this->assertSame(1350, $panels->first()->height);

            // And they are the post's sequence, not candidates for its cover.
            $this->assertSame(
                AssetSource::Generated,
                $carousel->assets()->where('role', AssetRole::Hero)->firstOrFail()->source,
            );
        });

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/render')
            && $request['props']['total'] === 2
            && $request['width'] === 1080
            && $request['height'] === 1350);
    }

    /**
     * Each slide is drawn by the template its shape asks for.
     *
     * The change this whole release is about. One template meant a six slide
     * carousel was six identical rectangles, which is not a writing problem: a
     * figure could not be shown as a figure and the cover was drawn exactly like
     * step four.
     */
    #[Test]
    public function every_slide_is_drawn_through_the_layout_it_asked_for(): void
    {
        $compositions = [];

        Http::fake(['*/render' => function ($request) use (&$compositions) {
            $compositions[] = $request['composition'];

            return Http::response($this->png(), 200, ['Content-Type' => 'image/png']);
        }]);
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        $this->fakeStudio(
            answers: [$this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26'])],
            draft: fn ($request) => $this->channelOf($request) === 'instagram'
                ? $this->candidate($request, slides: [
                    ['layout' => 'cover', 'heading' => 'Your routine is not the problem', 'kicker' => 'Lisbon homes'],
                    ['layout' => 'contrast', 'heading' => 'What people check versus what matters',
                        'before' => 'The surfaces that look worst', 'after' => 'The areas nothing reaches'],
                    ['layout' => 'step', 'heading' => 'Notice what accumulated', 'body' => 'Look past the surfaces.'],
                    ['layout' => 'step', 'heading' => 'Choose the next move', 'body' => 'Upkeep, or a reset.'],
                    ['layout' => 'checklist', 'heading' => 'Signs you need more',
                        'items' => ['It never feels reset', 'Areas are skipped', 'Limescale returns']],
                    ['layout' => 'cta', 'heading' => 'Save this for next month', 'action' => 'Save the post'],
                ])
                : null,
        );

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $this->assertSame(
            ['cover', 'contrast', 'step', 'step', 'checklist', 'cta'],
            $compositions,
        );

        app(CurrentProject::class)->run($this->project, function (): void {
            $carousel = ContentItem::query()
                ->where('channel_type', 'instagram')
                ->firstOrFail();

            $slides = $carousel->channel_payload['slides'];

            // Numbered by step rather than by slide. The third slide is step
            // one, because a reader counting "1, 2" against a six panel carousel
            // is counting the argument, not the deck.
            $this->assertSame('1', $slides[2]['fields']['step']);
            $this->assertSame('2', $slides[3]['fields']['step']);

            // The heading reaches the caption and the alt text whatever the
            // layout, including the two whose templates never draw it.
            $this->assertStringContainsString('Your routine is not the problem', $carousel->body_markdown);
        });
    }

    /**
     * The two positions that make the format work are not the model's to move.
     *
     * The hook decides whether anything after it is read, and a carousel that
     * ends without asking for something has spent the attention it earned and
     * banked nothing. A model that opens with step one still produces a cover.
     */
    #[Test]
    public function the_first_slide_is_a_cover_and_the_last_is_an_ask_whatever_was_returned(): void
    {
        $compositions = [];

        Http::fake(['*/render' => function ($request) use (&$compositions) {
            $compositions[] = $request['composition'];

            return Http::response($this->png(), 200, ['Content-Type' => 'image/png']);
        }]);
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        $this->fakeStudio(
            answers: [$this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26'])],
            draft: fn ($request) => $this->channelOf($request) === 'instagram'
                ? $this->candidate($request, slides: [
                    ['layout' => 'step', 'heading' => 'Straight into the middle', 'body' => 'No opening at all.'],
                    ['layout' => 'statement', 'heading' => 'A thought'],
                    ['layout' => 'step', 'heading' => 'And it just stops', 'body' => 'Nothing is asked for.'],
                ])
                : null,
        );

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $this->assertSame(['cover', 'statement', 'cta'], $compositions);
    }

    /**
     * A figure the idea cannot source is not drawn as a measurement.
     *
     * The strongest layout in the set and therefore the most dangerous: a number
     * at 300px reads as established fact, so it is the most damaging thing this
     * engine could invent. The sentence survives — it was still worth writing —
     * but it is drawn as a statement rather than presented as evidence.
     */
    #[Test]
    public function a_figure_the_idea_cannot_source_is_redrawn_as_a_plain_statement(): void
    {
        $compositions = [];

        Http::fake(['*/render' => function ($request) use (&$compositions) {
            $compositions[] = $request['composition'];

            return Http::response($this->png(), 200, ['Content-Type' => 'image/png']);
        }]);
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        $this->fakeStudio(
            answers: [$this->proposal(
                dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26'],
                // The one figure this month can actually stand behind. Written
                // as a numeral, which is the rule: evidence spelling a number
                // as a word does not source a digit, because teaching the
                // matcher to read "four" means teaching it "quatro", "четыре"
                // and "чотири" too, and a guard that is only right in English
                // is a guard that lets figures through in three languages.
                evidence: ['4 internal projects run on the engine.'],
            )],
            draft: fn ($request) => $this->channelOf($request) === 'instagram'
                ? $this->candidate($request, slides: [
                    ['layout' => 'cover', 'heading' => 'The number nobody checks'],
                    ['layout' => 'stat', 'heading' => 'projects run on it', 'figure' => '4'],
                    ['layout' => 'stat', 'heading' => 'faster, apparently', 'figure' => '73%'],
                    ['layout' => 'cta', 'heading' => 'Save this'],
                ])
                : null,
        );

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        // The four is in the evidence and stays a figure. The 73% appears
        // nowhere the idea was planned from, so it loses the layout that would
        // have presented it as measured.
        $this->assertSame(['cover', 'stat', 'statement', 'cta'], $compositions);
    }

    /**
     * A decimal in the evidence sources a decimal on the slide.
     *
     * Found by review, and it had made the guard almost useless: the figure's
     * digits were compared against the evidence flattened on non-digits, so
     * "4.9" became "49" while "4.9 rating" became "4" and "9" and the two could
     * never agree. Every decimal and every thousands-grouped number was demoted
     * to a statement — including "4.9", which the prompt itself offers as an
     * example of a good figure.
     */
    #[Test]
    public function a_decimal_figure_is_sourced_from_the_decimal_in_the_evidence(): void
    {
        $compositions = [];

        Http::fake(['*/render' => function ($request) use (&$compositions) {
            $compositions[] = $request['composition'];

            return Http::response($this->png(), 200, ['Content-Type' => 'image/png']);
        }]);
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        $this->fakeStudio(
            answers: [$this->proposal(
                dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26'],
                evidence: ['A 4.9 rating across 1,200 reviews.'],
            )],
            draft: fn ($request) => $this->channelOf($request) === 'instagram'
                ? $this->candidate($request, slides: [
                    ['layout' => 'cover', 'heading' => 'The number nobody checks'],
                    ['layout' => 'stat', 'heading' => 'average rating', 'figure' => '4.9'],
                    ['layout' => 'stat', 'heading' => 'reviews', 'figure' => '1,200'],
                    ['layout' => 'cta', 'heading' => 'Save this'],
                ])
                : null,
        );

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        // Both survive: separators are dropped rather than interpreted, because
        // "4.9" and "4,9" are the same figure in the languages this engine
        // writes and no locale reaches this comparison.
        $this->assertSame(['cover', 'stat', 'stat', 'cta'], $compositions);
    }

    /**
     * Dropping a slide does not cost the carousel its cover or its ending.
     *
     * Found by review. Position was decided against the model's raw answer, so a
     * final slide that omitted its heading — plausible for a `cta`, whose real
     * payload is its `action` — was filtered out and left nothing at the last
     * position at all. The carousel then ended on a middle layout with nothing
     * asked for, which is precisely what the position rule exists to prevent.
     */
    #[Test]
    public function a_slide_the_model_left_headless_does_not_cost_the_carousel_its_ending(): void
    {
        $compositions = [];

        Http::fake(['*/render' => function ($request) use (&$compositions) {
            $compositions[] = $request['composition'];

            return Http::response($this->png(), 200, ['Content-Type' => 'image/png']);
        }]);
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        $this->fakeStudio(
            answers: [$this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26'])],
            draft: fn ($request) => $this->channelOf($request) === 'instagram'
                ? $this->candidate($request, slides: [
                    ['layout' => 'cover', 'heading' => 'An opening'],
                    ['layout' => 'step', 'heading' => 'The middle', 'body' => 'Something happens.'],
                    ['layout' => 'cta', 'heading' => 'Save this'],
                    // No heading at all, and therefore not a slide.
                    ['layout' => 'cta', 'action' => 'Follow'],
                ])
                : null,
        );

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $this->assertSame(['cover', 'step', 'cta'], $compositions);
    }

    /**
     * An overlong optional field is trimmed, not thrown.
     *
     * Found by review. These limits are tight and the model is never told them,
     * so a 62-character kicker discarded the whole candidate and burned a retry
     * — and a batch where every candidate overran failed the channel outright,
     * which is the opposite of what the parser promises.
     */
    #[Test]
    public function an_overlong_optional_field_is_trimmed_rather_than_losing_the_post(): void
    {
        Http::fake(['*/render' => Http::response($this->png(), 200, [
            'Content-Type' => 'image/png',
        ])]);
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        $this->fakeStudio(
            answers: [$this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26'])],
            draft: fn ($request) => $this->channelOf($request) === 'instagram'
                ? $this->candidate($request, slides: [
                    [
                        'layout' => 'cover',
                        'heading' => 'An opening',
                        // 60 is the limit; this is well past it.
                        'kicker' => str_repeat('Lisbon homes and everything in them, ', 4),
                    ],
                    ['layout' => 'cta', 'heading' => 'Save this'],
                ])
                : null,
        );

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $carousel = ContentItem::query()->where('channel_type', 'instagram')->firstOrFail();
            $kicker = $carousel->channel_payload['slides'][0]['fields']['kicker'];

            // The post survived, and the field fits the frame it is drawn in.
            $this->assertLessThanOrEqual(60, mb_strlen($kicker));
        });
    }

    /**
     * A layout missing a half it cannot be drawn without falls back.
     *
     * A contrast with one side is not a contrast; it is a statement in a
     * template that would draw an empty coloured band where the other half goes.
     */
    #[Test]
    public function a_layout_missing_a_field_it_needs_falls_back_rather_than_drawing_a_gap(): void
    {
        $compositions = [];

        Http::fake(['*/render' => function ($request) use (&$compositions) {
            $compositions[] = $request['composition'];

            return Http::response($this->png(), 200, ['Content-Type' => 'image/png']);
        }]);
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        $this->fakeStudio(
            answers: [$this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26'])],
            draft: fn ($request) => $this->channelOf($request) === 'instagram'
                ? $this->candidate($request, slides: [
                    ['layout' => 'cover', 'heading' => 'An opening'],
                    ['layout' => 'contrast', 'heading' => 'Only one side of it', 'before' => 'What people assume'],
                    ['layout' => 'checklist', 'heading' => 'A list with nothing in it', 'items' => []],
                    ['layout' => 'cta', 'heading' => 'Save this'],
                ])
                : null,
        );

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $this->assertSame(['cover', 'statement', 'statement', 'cta'], $compositions);
    }

    #[Test]
    public function a_slide_that_will_not_draw_does_not_lose_the_others(): void
    {
        $drawn = 0;

        Http::fake(['*/render' => function () use (&$drawn) {
            $drawn++;

            return $drawn === 1
                ? Http::response(['message' => 'the template threw'], 500)
                : Http::response($this->png(), 200, ['Content-Type' => 'image/png']);
        }]);
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        $this->fakeStudio(answers: [
            $this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26']),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $carousel = ContentItem::query()
                ->where('type', ContentItemType::SocialPost)
                ->where('channel_type', 'instagram')
                ->firstOrFail();

            // One panel, not zero. A carousel missing a slide is still a
            // carousel; failing the batch would throw away the ones that drew.
            $this->assertSame(
                1,
                Asset::query()
                    ->where('content_item_id', $carousel->getKey())
                    ->where('role', AssetRole::Inline)
                    ->count(),
            );
        });
    }

    /**
     * Asking the Studio to change the picture changes all of them.
     *
     * The bug this guards was reported from the review screen: a seven-slide
     * carousel was told to use the brief's fresh colours, and one photograph
     * came back. {@see ContentStudioAssistant::reviseImage()} bought variants
     * and stopped, so the six assets that actually read the brand's colours —
     * the panels — kept a palette four brief versions old, and the single asset
     * that was redrawn is the one the brief barely reaches.
     */
    #[Test]
    public function revising_a_carousels_picture_redraws_its_slides_too(): void
    {
        config(['content_studio.renderer.url' => 'http://renderer:3020']);
        Http::fake(['*/render' => Http::response($this->png(), 200, [
            'Content-Type' => 'image/png',
        ])]);

        $this->fakeStudio(answers: [
            $this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26']),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $carousel = ContentItem::query()
                ->where('type', ContentItemType::SocialPost)
                ->where('channel_type', 'instagram')
                ->firstOrFail();

            $before = Asset::query()
                ->where('content_item_id', $carousel->getKey())
                ->where('role', AssetRole::Inline)
                ->whereNull('superseded_at')
                ->pluck('id')
                ->all();

            $this->assertCount(2, $before);

            // No instruction: the brief has already been revised by the time a
            // redraw is queued, which is the path the review screen takes.
            $result = app(ContentStudioAssistant::class)->reviseImage(
                $carousel,
                null,
                1,
                app(UnmeteredSession::class),
            );

            $this->assertSame(2, $result['panels']);

            $after = Asset::query()
                ->where('content_item_id', $carousel->getKey())
                ->where('role', AssetRole::Inline)
                ->whereNull('superseded_at')
                ->pluck('id')
                ->all();

            // Still two live slides, and none of them the ones from before:
            // superseded rather than duplicated, so the carousel does not grow
            // a second slide two every time somebody edits it.
            $this->assertCount(2, $after);
            $this->assertSame([], array_intersect($before, $after));

            $this->assertSame(
                2,
                Asset::query()
                    ->where('content_item_id', $carousel->getKey())
                    ->where('role', AssetRole::Inline)
                    ->whereNotNull('superseded_at')
                    ->count(),
            );
        });
    }

    /**
     * And a post that is a photograph and a caption is left exactly alone.
     *
     * The guard on the other side: `drawPanels()` returns on an empty slide
     * list, so adding it to the redraw path may not start inventing panels for
     * every post that never had any.
     */
    #[Test]
    public function revising_a_plain_posts_picture_draws_no_panels(): void
    {
        config(['content_studio.renderer.url' => 'http://renderer:3020']);
        Http::fake(['*/render' => Http::response($this->png(), 200, [
            'Content-Type' => 'image/png',
        ])]);

        $this->fakeStudio(answers: [
            $this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26']),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $plain = ContentItem::query()
                ->where('type', ContentItemType::SocialPost)
                ->where('channel_type', '!=', 'instagram')
                ->firstOrFail();

            $result = app(ContentStudioAssistant::class)->reviseImage(
                $plain,
                null,
                1,
                app(UnmeteredSession::class),
            );

            $this->assertSame(0, $result['panels']);
            $this->assertSame(0, $plain->assets()->where('role', AssetRole::Inline)->count());
        });
    }

    #[Test]
    public function a_deployment_with_no_renderer_still_writes_the_post(): void
    {
        config(['content_studio.renderer.url' => null]);
        Http::fake();

        $this->fakeStudio(answers: [
            $this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26']),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $carousel = ContentItem::query()
                ->where('type', ContentItemType::SocialPost)
                ->where('channel_type', 'instagram')
                ->firstOrFail();

            $this->assertNotNull($carousel->body_markdown);
            $this->assertSame(
                0,
                Asset::query()
                    ->where('content_item_id', $carousel->getKey())
                    ->where('role', AssetRole::Inline)
                    ->count(),
            );
        });

        Http::assertNothingSent();
    }

    #[Test]
    public function an_operator_can_ask_for_other_pictures_without_changing_the_one_it_ships(): void
    {
        $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $draft = app(CurrentProject::class)->run($this->project, static fn (): ContentItem => ContentItem::query()
            ->where('type', ContentItemType::SocialPost)
            ->firstOrFail());

        $shipped = app(CurrentProject::class)->run($this->project, static fn (): string => (string) $draft
            ->assets()->where('role', AssetRole::Hero)->firstOrFail()->getKey());

        $this->postJson("/studio/drafts/{$draft->getKey()}/image", ['variants' => 3])
            ->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function () use ($draft, $shipped): void {
            $assets = Asset::query()->where('content_item_id', $draft->getKey())->get();

            $this->assertCount(4, $assets, 'Three candidates beside the one it already had.');
            $this->assertCount(3, $assets->where('role', AssetRole::Variant));

            // Until somebody picks one, the post still ships what it shipped.
            $this->assertSame(
                $shipped,
                (string) $assets->firstWhere('role', AssetRole::Hero)?->getKey(),
            );
        });
    }

    #[Test]
    public function a_note_about_a_picture_revises_the_brief_rather_than_the_prompt(): void
    {
        $revised = null;

        $this->fakeStudio(draft: function (ModelRequest $request) use (&$revised): ?string {
            if (! str_contains($request->instructions, 'art director')) {
                return null;
            }

            $revised = $request->prompt;

            return (string) json_encode([
                'subject' => 'limescale crusted around the base of a tap',
                'light' => 'hard low sun raking across it',
            ]);
        });

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $draft = app(CurrentProject::class)->run($this->project, static fn (): ContentItem => ContentItem::query()
            ->where('type', ContentItemType::SocialPost)
            ->firstOrFail());

        $this->postJson("/studio/drafts/{$draft->getKey()}/image", [
            'instruction' => 'Too clean. Show the actual residue.',
        ])->assertStatus(202);

        // The director is shown the brief as it stands, not a growing prompt.
        $this->assertStringContainsString('Too clean. Show the actual residue.', (string) $revised);
        $this->assertStringContainsString('a hand drawing a cloth through the dust', (string) $revised);

        app(CurrentProject::class)->run($this->project, function () use ($draft): void {
            $payload = $draft->fresh()->channel_payload;

            $this->assertSame('limescale crusted around the base of a tap', $payload['visual']['subject']);
            $this->assertSame('hard low sun raking across it', $payload['visual']['light']);
            // Fields the note said nothing about keep what they had, or a
            // revision would quietly undo every earlier one.
            $this->assertSame(
                'the cloth lifts a clean line through the grime and leaves the rest of it there',
                $payload['visual']['action'],
            );
            $this->assertSame('Too clean. Show the actual residue.', $payload['visual_notes'][0]['said']);
        });
    }

    #[Test]
    public function a_real_photograph_can_be_used_and_is_cropped_to_the_channel(): void
    {
        $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $draft = app(CurrentProject::class)->run($this->project, static fn (): ContentItem => ContentItem::query()
            ->where('type', ContentItemType::SocialPost)
            ->where('channel_type', 'instagram')
            ->firstOrFail());

        // A wide photograph on a channel that shows 4:5. Accepting it as it
        // came would make it the one picture in the set the feed letterboxes.
        $this->post("/studio/drafts/{$draft->getKey()}/photo", [
            'photo' => UploadedFile::fake()->image('van-outside-alfama.jpg', 3000, 2400),
        ])->assertOk();

        app(CurrentProject::class)->run($this->project, function () use ($draft): void {
            $photo = Asset::query()
                ->where('content_item_id', $draft->getKey())
                ->where('source', AssetSource::Uploaded)
                ->firstOrFail();

            $this->assertSame(AssetRole::Variant, $photo->role);
            $this->assertSame(1080, $photo->width);
            $this->assertSame(1350, $photo->height);
            $this->assertSame('van-outside-alfama', $photo->alt);
            $this->assertTrue(Storage::disk('public')->exists($photo->path));

            // A candidate, not a decision. Choosing stays one deliberate act
            // whatever the picture came from.
            $this->assertSame(
                AssetSource::Generated,
                $draft->assets()->where('role', AssetRole::Hero)->firstOrFail()->source,
            );
        });
    }

    #[Test]
    public function a_photograph_smaller_than_the_frame_keeps_the_ratio_rather_than_being_upscaled(): void
    {
        $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $draft = app(CurrentProject::class)->run($this->project, static fn (): ContentItem => ContentItem::query()
            ->where('type', ContentItemType::SocialPost)
            ->where('channel_type', 'instagram')
            ->firstOrFail());

        // Only 1200 tall against a 1350 frame. Stretching it to fit produces a
        // soft picture that reads as a bad generation, which is the impression
        // using a real photograph exists to avoid.
        $this->post("/studio/drafts/{$draft->getKey()}/photo", [
            'photo' => UploadedFile::fake()->image('crew.jpg', 2000, 1200),
        ])->assertOk();

        app(CurrentProject::class)->run($this->project, function () use ($draft): void {
            $photo = Asset::query()
                ->where('content_item_id', $draft->getKey())
                ->where('source', AssetSource::Uploaded)
                ->firstOrFail();

            $this->assertSame(960, $photo->width);
            $this->assertSame(1200, $photo->height);
            $this->assertSame(
                1080 / 1350,
                $photo->width / $photo->height,
                'Smaller than the frame, but still the shape the channel shows.',
            );
        });
    }

    #[Test]
    public function something_too_small_to_publish_is_refused_with_its_size(): void
    {
        $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $draft = app(CurrentProject::class)->run($this->project, static fn (): ContentItem => ContentItem::query()
            ->where('type', ContentItemType::SocialPost)
            ->firstOrFail());

        $this->post("/studio/drafts/{$draft->getKey()}/photo", [
            'photo' => UploadedFile::fake()->image('icon.png', 120, 120),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That picture is 120×120. Both sides need to be at least 400.');

        app(CurrentProject::class)->run($this->project, function () use ($draft): void {
            $this->assertSame(
                0,
                Asset::query()
                    ->where('content_item_id', $draft->getKey())
                    ->where('source', AssetSource::Uploaded)
                    ->count(),
            );
        });
    }

    #[Test]
    public function choosing_a_candidate_swaps_it_in_and_keeps_the_one_it_replaced(): void
    {
        $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $draft = app(CurrentProject::class)->run($this->project, static fn (): ContentItem => ContentItem::query()
            ->where('type', ContentItemType::SocialPost)
            ->firstOrFail());

        $this->postJson("/studio/drafts/{$draft->getKey()}/image", ['variants' => 2])->assertStatus(202);

        [$was, $variant] = app(CurrentProject::class)->run($this->project, static fn (): array => [
            (string) $draft->assets()->where('role', AssetRole::Hero)->firstOrFail()->getKey(),
            (string) $draft->assets()->where('role', AssetRole::Variant)->firstOrFail()->getKey(),
        ]);

        $this->postJson("/studio/drafts/{$draft->getKey()}/image/{$variant}")->assertOk();

        app(CurrentProject::class)->run($this->project, function () use ($draft, $was, $variant): void {
            $assets = Asset::query()->where('content_item_id', $draft->getKey())->get();

            $this->assertSame($variant, (string) $assets->firstWhere('role', AssetRole::Hero)?->getKey());

            // The picture it replaced is retired, not deleted: an operator who
            // preferred the one they rejected can still have it back.
            $replaced = $assets->firstWhere('id', $was);

            $this->assertNotNull($replaced);
            $this->assertSame(AssetRole::Variant, $replaced->role);
            $this->assertNotNull($replaced->superseded_at);

            $this->assertSame($variant, $draft->fresh()->channel_payload['asset_id']);
        });
    }

    #[Test]
    public function the_picture_a_candidate_replaced_stays_visible_so_it_can_be_put_back(): void
    {
        $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $draft = app(CurrentProject::class)->run($this->project, static fn (): ContentItem => ContentItem::query()
            ->where('type', ContentItemType::SocialPost)
            ->firstOrFail());

        $this->postJson("/studio/drafts/{$draft->getKey()}/image", ['variants' => 1])->assertStatus(202);

        $variant = app(CurrentProject::class)->run($this->project, static fn (): string => (string) $draft
            ->assets()->where('role', AssetRole::Variant)->firstOrFail()->getKey());

        $plan = $this->postJson("/studio/drafts/{$draft->getKey()}/image/{$variant}")
            ->assertOk()
            ->json('plan');

        /** @var list<array<string, mixed>> $ideas */
        $ideas = $plan['ideas'];
        $assets = [];

        foreach ($ideas as $idea) {
            foreach ((array) $idea['drafts'] as $candidate) {
                if ($candidate['id'] === (string) $draft->getKey()) {
                    $assets = $candidate['assets'];
                }
            }
        }

        // Choosing retires the picture it replaced rather than deleting it, and
        // the relation the payload reads filters retired rows out. Reading the
        // filtered one made the replaced picture vanish the moment it was
        // replaced, so "put back the one you rejected" was unreachable.
        $this->assertCount(2, $assets);
        $this->assertCount(1, array_filter($assets, static fn (array $a): bool => $a['retired']));
        $this->assertCount(1, array_filter($assets, static fn (array $a): bool => $a['chosen']));
    }

    #[Test]
    public function a_renderer_that_will_not_answer_costs_the_panels_and_not_the_drafts(): void
    {
        config(['content_studio.renderer.url' => 'http://renderer:3020']);
        Http::fake(['*/render' => fn () => throw new ConnectionException('connection refused')]);

        $this->fakeStudio(answers: [
            $this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26']),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            // The drafts are written and paid for before the panels are drawn.
            // A refused connection used to escape and fail the run, and the
            // retry then found every channel drafted, returned "created: 0" and
            // never illustrated anything — one blip, a carousel with no
            // pictures and no error anybody could see.
            $this->assertSame(2, ContentItem::query()->count());
            $this->assertSame(
                0,
                PipelineRun::query()->where('status', PipelineRunStatus::Failed)->count(),
            );
        });
    }

    #[Test]
    public function candidates_nobody_chose_are_not_delivered_to_a_receiver(): void
    {
        $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $draft = app(CurrentProject::class)->run($this->project, static fn (): ContentItem => ContentItem::query()
            ->where('type', ContentItemType::SocialPost)
            ->firstOrFail());

        $this->postJson("/studio/drafts/{$draft->getKey()}/image", ['variants' => 3])->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function () use ($draft): void {
            $payload = WebhookPayload::for($draft->fresh(), WebhookEvent::Published, 'delivery-1');
            /** @var list<array<string, mixed>> $images */
            $images = $payload['content']['images'] ?? [];
            $roles = array_values(array_unique(array_column($images, 'role')));

            // A variant is a live row until something promotes it, so without a
            // role filter every rejected candidate went out in the payload and
            // a receiver rendering `images` attached the drafts nobody picked.
            $this->assertSame(['hero'], $roles);
        });
    }

    #[Test]
    public function the_pictures_the_studio_buys_reach_the_cost_rows(): void
    {
        $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $run = PipelineRun::query()->where('input->action', 'generate_idea')->firstOrFail();
            $step = $run->steps()->firstOrFail();

            // §6's per-unit cost is a sum of step rows, and an image is priced
            // per picture rather than per token — so it only lands there if
            // something records it. Nothing did.
            $this->assertGreaterThanOrEqual(
                2 * FakeImageGeneration::COST_MICROS,
                $step->cost_micros,
                'The drafts cost tokens and two pictures; only the tokens were being counted.',
            );
        });
    }

    #[Test]
    public function the_screen_keeps_watching_while_any_idea_of_the_week_is_still_drafting(): void
    {
        $this->fakeStudio(answers: [
            $this->proposal(dates: ['2026-08-03', '2026-08-04', '2026-08-19', '2026-08-26']),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        // Two children finished; leave one of them running to stand for a
        // sibling that has not settled. The screen stops polling when what it
        // is watching settles, and watching the newest run only works while the
        // expensive queue happens to run one process in creation order.
        app(CurrentProject::class)->run($this->project, function (): void {
            PipelineRun::query()
                ->where('input->action', 'generate_idea')
                ->oldest()
                ->firstOrFail()
                ->forceFill(['status' => PipelineRunStatus::Running, 'finished_at' => null])
                ->save();
        });

        $operation = $this->get('/social/plan?month=2026-08')
            ->assertOk()
            ->viewData('page')['props']['operation'];

        $this->assertSame('running', $operation['status']);
        $this->assertSame('generate_idea', $operation['action']);
    }

    #[Test]
    public function a_carousel_gets_its_panels_even_where_no_image_provider_is_configured(): void
    {
        // A renderer and no image model is a supported deployment: the panels
        // are the part that costs nothing. Nesting them inside the photograph
        // meant such a deployment drew no slides at all.
        $this->app->instance(ImageGenerationProvider::class, new class extends FakeImageGeneration
        {
            public function isConfigured(): bool
            {
                return false;
            }
        });

        config(['content_studio.renderer.url' => 'http://renderer:3020']);
        Http::fake(['*/render' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $this->fakeStudio(answers: [
            $this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26']),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $carousel = ContentItem::query()
                ->where('type', ContentItemType::SocialPost)
                ->where('channel_type', 'instagram')
                ->firstOrFail();

            $this->assertSame(
                0,
                $carousel->assets()->where('role', AssetRole::Hero)->count(),
                'No provider, so no photograph — which is the point of the test.',
            );
            $this->assertSame(2, $carousel->assets()->where('role', AssetRole::Inline)->count());
        });
    }

    #[Test]
    public function a_carousel_panel_cannot_be_promoted_to_the_post_s_picture(): void
    {
        config(['content_studio.renderer.url' => 'http://renderer:3020']);
        Http::fake(['*/render' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $this->fakeStudio(answers: [
            $this->proposal(dates: ['2026-08-04', '2026-08-12', '2026-08-19', '2026-08-26']),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        [$draft, $panel, $hero] = app(CurrentProject::class)->run($this->project, static function (): array {
            $item = ContentItem::query()
                ->where('type', ContentItemType::SocialPost)
                ->where('channel_type', 'instagram')
                ->firstOrFail();

            return [
                (string) $item->getKey(),
                (string) $item->assets()->where('role', AssetRole::Inline)->firstOrFail()->getKey(),
                (string) $item->assets()->where('role', AssetRole::Hero)->firstOrFail()->getKey(),
            ];
        });

        // A panel belongs to the same draft, so ownership alone let it through:
        // the post lost the picture it ships and the carousel lost a step out
        // of the middle of its sequence.
        $this->postJson("/studio/drafts/{$draft}/image/{$panel}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'That picture is part of the post rather than a choice for it.');

        app(CurrentProject::class)->run($this->project, function () use ($draft, $panel, $hero): void {
            $item = ContentItem::query()->whereKey($draft)->firstOrFail();

            $this->assertSame($hero, (string) $item->assets()->where('role', AssetRole::Hero)->firstOrFail()->getKey());
            $this->assertSame(2, $item->assets()->where('role', AssetRole::Inline)->count());
            $this->assertSame(
                AssetRole::Inline,
                Asset::query()->whereKey($panel)->firstOrFail()->role,
            );
        });
    }

    /**
     * The reader never sees the idea's title, so the post may not lean on it.
     *
     * The title and thesis are in the prompt because the writer needs to know
     * what it is writing about. What that produced was posts written as the
     * title's second half — "Routine care holds the baseline; a deep clean goes
     * beyond usual cleaning" only parses under a headline asking when routine
     * stops being enough — and on the channel there is no headline.
     */
    #[Test]
    public function the_writer_is_told_the_post_stands_without_its_title(): void
    {
        $fake = $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $drafts = array_values(array_filter(
            $fake->sent(),
            static fn (ModelRequest $call): bool => $call->role === 'draft',
        ));

        $this->assertNotSame([], $drafts);

        foreach ($drafts as $call) {
            $this->assertStringContainsString('working notes, not part of the post', $call->instructions);
            $this->assertStringContainsString('The reader never sees them', $call->instructions);
        }
    }

    /**
     * The requirement the picture is judged against, given to the party that
     * writes the brief rather than only to the provider that draws it.
     */
    #[Test]
    public function the_writer_is_told_what_the_picture_has_to_show(): void
    {
        $fake = $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $drafts = array_values(array_filter(
            $fake->sent(),
            static fn (ModelRequest $call): bool => $call->role === 'draft',
        ));

        $this->assertNotSame([], $drafts);

        foreach ($drafts as $call) {
            $this->assertStringContainsString('What this picture has to show:', $call->prompt);
            // The prop rule, in the contract as well as in the provider prompt.
            $this->assertStringContainsString('an object that exists to be read', $call->prompt);
        }
    }

    /**
     * A weak picture brief costs one more call, never the post.
     *
     * The first answer is refused with the reason and the writer asked again;
     * a second answer is taken whatever its brief looks like. The alternative —
     * refusing both — spends a written, fact-checked candidate to fix its
     * photograph, and an unillustrated draft is already something this pipeline
     * survives.
     */
    #[Test]
    public function a_weak_picture_brief_is_corrected_once_and_then_accepted(): void
    {
        $seen = 0;

        $fake = $this->fakeStudio(draft: function (ModelRequest $request) use (&$seen): ?string {
            if ($this->channelOf($request) !== 'x') {
                return null;
            }

            $seen++;

            // Always the same refusable brief: a prop, and no work in the frame.
            return (string) json_encode([
                'segments' => ['A compact X post.'],
                'link' => null,
                'chain_reason' => null,
                'visual' => [
                    'subject' => 'a hand resting on a clipboard beside a mug',
                    'composition' => 'overhead',
                    'action' => 'the hand holds the clipboard still',
                    'location' => 'a kitchen',
                ],
            ]);
        });

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $corrections = array_values(array_filter(
            $fake->sent(),
            static fn (ModelRequest $call): bool => $call->role === 'draft'
                && str_contains($call->prompt, 'Your previous answer was invalid')
                && str_contains($call->prompt, 'clipboard'),
        ));

        // Every X candidate was refused once and asked again — and the second
        // answer, identical, was kept.
        $this->assertNotSame([], $corrections);
        $this->assertSame($seen, count($corrections) * 2);

        app(CurrentProject::class)->run($this->project, function (): void {
            $x = ContentItem::query()->where('channel_type', 'x')->firstOrFail();

            $this->assertSame(
                'a hand resting on a clipboard beside a mug',
                $x->channel_payload['visual']['subject'],
            );
        });
    }

    /**
     * A month may not be one photograph taken fourteen times.
     *
     * Told only to show work in contact with a surface, the writer finds the
     * one shot that always satisfies that and takes it every time: a
     * regenerated set came back with eight of fourteen briefs describing a
     * gloved hand and a detailing brush in a groove, across five unrelated
     * ideas. The rule is the same one `$siblings` applies to the words, one
     * level up — this idea is shown what the month already looks like.
     */
    #[Test]
    public function a_draft_is_shown_the_photographs_the_month_already_has(): void
    {
        $fake = $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $told = array_values(array_filter(
            $fake->sent(),
            static fn (ModelRequest $call): bool => $call->role === 'draft'
                && str_contains($call->prompt, 'Photographs already briefed for this month'),
        ));

        // The first idea of a month has nothing to be shown; everything after
        // it does, and what it is shown is the subject the earlier draft used.
        $this->assertNotSame([], $told);
        $this->assertStringContainsString(
            'a hand drawing a cloth through the dust',
            $told[0]->prompt,
        );
        $this->assertStringContainsString('Brief a different photograph', $told[0]->prompt);
    }

    /**
     * The history survives the run that wrote it.
     *
     * An idea whose Threads post exists and whose X post is being written in a
     * later run is the case the in-memory list cannot cover, and the case where
     * a repeat is most visible: the same idea, twice, side by side.
     */
    #[Test]
    public function a_picture_briefed_in_an_earlier_run_still_counts(): void
    {
        $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        // One channel of one idea survives; the rest of the month is cleared,
        // so anything the next draft is shown can only have come from the row.
        $idea = app(CurrentProject::class)->run($this->project, function (): string {
            $kept = ContentItem::query()->whereNotNull('content_idea_id')->firstOrFail();

            ContentItem::query()->whereKeyNot($kept->getKey())->delete();
            $kept->forceFill(['channel_payload' => [
                ...$kept->channel_payload,
                'visual' => ['subject' => 'a squeegee crossing a fogged shower screen'],
            ]])->save();

            return (string) $kept->content_idea_id;
        });

        $fake = $this->fakeStudio();
        $this->postJson("/studio/ideas/{$idea}/generate")->assertStatus(202);

        $told = array_values(array_filter(
            $fake->sent(),
            static fn (ModelRequest $call): bool => $call->role === 'draft'
                && str_contains($call->prompt, 'a squeegee crossing a fogged shower screen'),
        ));

        $this->assertNotSame([], $told);
    }

    /**
     * The picture the month chose reaches the writer, and outranks its own.
     *
     * The idea carries a `shot` assigned when all twenty were planned together.
     * A draft free to invent its own subject invents the same one as its
     * siblings, because they are written in parallel and none can see the
     * others.
     */
    #[Test]
    public function a_draft_is_given_the_shot_its_month_assigned_it(): void
    {
        $fake = $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();

        app(CurrentProject::class)->run($this->project, function (): void {
            ContentIdea::query()->update(['shot' => 'a squeegee crossing a fogged shower screen']);
        });

        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $told = array_values(array_filter(
            $fake->sent(),
            static fn (ModelRequest $call): bool => $call->role === 'draft'
                && str_contains($call->prompt, 'a squeegee crossing a fogged shower screen'),
        ));

        $this->assertNotSame([], $told);
        $this->assertStringContainsString('sharpen it, do not substitute it', $told[0]->prompt);
    }

    /**
     * An idea planned before the column existed still drafts.
     *
     * A null shot is the old behaviour — the writer invents one — and every
     * idea in every plan made before this release has one.
     */
    #[Test]
    public function an_idea_with_no_assigned_shot_still_draws_a_picture(): void
    {
        $fake = $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();

        app(CurrentProject::class)->run($this->project, function (): void {
            ContentIdea::query()->update(['shot' => null]);
        });

        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $drafts = array_values(array_filter(
            $fake->sent(),
            static fn (ModelRequest $call): bool => $call->role === 'draft',
        ));

        $this->assertNotSame([], $drafts);

        foreach ($drafts as $call) {
            $this->assertStringNotContainsString('chosen with the rest of the month', $call->prompt);
        }

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertGreaterThan(0, ContentItem::query()->whereNotNull('channel_type')->count());
        });
    }

    /**
     * The brand does not quote its own website at the reader.
     *
     * Evidence arrives written *about* the business, because that is how a
     * planner reading a website records it. Handed straight to a writer it
     * produced "Our homepage states 97% on-time arrivals".
     */
    #[Test]
    public function the_writer_is_told_the_facts_are_its_own(): void
    {
        $fake = $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $drafts = array_values(array_filter(
            $fake->sent(),
            static fn (ModelRequest $call): bool => $call->role === 'draft',
        ));

        $this->assertNotSame([], $drafts);

        foreach ($drafts as $call) {
            $this->assertStringContainsString('You are the business', $call->prompt);
            $this->assertStringContainsString('never mention the website', $call->prompt);
        }
    }

    /**
     * Every register is asked to spend a fact, except the one that may not.
     *
     * `life` exists because the service is why the moment is possible and is
     * not its subject. Asked for a specific anyway, the first post drafted
     * under the rule ended "Recurring Home Cleaning starts at €18/hour" — a
     * price list wearing a Sunday afternoon.
     */
    #[Test]
    public function the_personal_register_is_not_asked_to_quote_a_price(): void
    {
        $fake = $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();

        app(CurrentProject::class)->run($this->project, function (): void {
            ContentIdea::query()->limit(1)->update(['kind' => PostKind::Life]);
        });

        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        $byRegister = ['life' => [], 'other' => []];

        foreach ($fake->sent() as $call) {
            if ($call->role !== 'draft') {
                continue;
            }

            $key = str_contains($call->instructions, 'This is a post about somebody at home') ? 'life' : 'other';
            $byRegister[$key][] = str_contains($call->prompt, 'Use at least one specific');
        }

        $this->assertNotSame([], $byRegister['life']);
        $this->assertNotSame([], $byRegister['other']);
        $this->assertNotContains(true, $byRegister['life'], 'A life post was asked for a price.');
        $this->assertNotContains(false, $byRegister['other'], 'Every other register still spends a fact.');
    }

    #[Test]
    public function every_channel_gets_a_directed_picture_at_its_own_crop(): void
    {
        $this->fakeStudio();

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $sizes = ContentItem::query()
                ->with('assets')
                ->get()
                ->mapWithKeys(static fn (ContentItem $item): array => [
                    $item->channel_type => [
                        $item->assets->first()?->width,
                        $item->assets->first()?->height,
                    ],
                ])
                ->all();

            // 1200×630 is an Open Graph card. It was what all three used to get.
            $this->assertSame([1080, 1080], $sizes['threads']);
            $this->assertSame([1200, 675], $sizes['x']);
            $this->assertSame([1080, 1350], $sizes['instagram']);

            $calls = $this->images->calls();

            $this->assertCount(4, $calls);

            foreach ($calls as $call) {
                foreach (['Subject:', 'Composition:', 'Action:', 'Location:', 'Style:', 'Camera:', 'Light:'] as $element) {
                    $this->assertStringContainsString($element, $call['prompt']);
                }

                $this->assertStringContainsString('a hand drawing a cloth through the dust', $call['prompt']);
                $this->assertStringContainsString('no text, no lettering', $call['prompt']);
                // The sentence that produced the stock illustrations.
                $this->assertStringNotContainsString('article titled', $call['prompt']);
            }

            $tall = array_values(array_filter(
                $calls,
                static fn (array $call): bool => $call['options']['height'] === 1350,
            ));

            $this->assertCount(1, $tall);
            $this->assertStringContainsString('framed for a 4:5 crop', $tall[0]['prompt']);
        });
    }

    /**
     * The old address still works, and keeps the month.
     *
     * `/studio` was a top-level screen for a release and is in browser
     * histories and in the product notes. `Route::redirect()` would have
     * dropped the query string, so a bookmarked `/studio?month=2026-09` would
     * have landed silently on today — the sort of breakage nobody reports
     * because it looks like it worked.
     */
    #[Test]
    public function the_old_studio_address_redirects_and_keeps_the_month(): void
    {
        $this->get('/studio?month=2026-09')
            ->assertRedirect('/social/plan?month=2026-09');

        $this->get('/studio')->assertRedirect('/social/plan');
    }

    #[Test]
    public function another_projects_plan_is_not_addressable_through_studio_routes(): void
    {
        $other = Project::factory()->create();
        $plan = app(CurrentProject::class)->run(
            $other,
            fn (): ContentPlan => ContentPlan::factory()->forMonth('2026-08-01')->create(),
        );

        $this->postJson("/studio/plans/{$plan->getKey()}/accept", ['version' => 1])
            ->assertNotFound();
    }

    #[Test]
    public function studio_usage_is_metered_to_the_project_that_spent_it(): void
    {
        $this->fakeModel([$this->proposal(), $this->proposal()]);
        $other = Project::factory()->create(['name' => 'Other project']);

        app(CurrentProject::class)->run($other, function () use ($other): void {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create();

            app(PipelineRunner::class)->start(
                ContentStudioPipeline::key(),
                $other,
                [
                    'action' => 'proposal',
                    'content_plan_id' => $plan->getKey(),
                ],
            );
        });

        $this->postJson('/studio/propose', ['month' => '2026-08'])->assertStatus(202);

        $this->assertSame(
            1,
            PipelineRun::acrossProjects()->where('project_id', $other->getKey())->count(),
        );
        $this->assertSame(
            1,
            PipelineRun::acrossProjects()->where('project_id', $this->project->getKey())->count(),
        );

        $this->get(route('metering.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('by_pipeline.0.pipeline', 'content_studio')
                ->where('by_pipeline.0.runs', 1)
                ->where('by_step.0.runs', 1)
            );
    }

    /** One real PNG, so what the renderer returns is what gets stored. */
    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }

    /** @param list<string> $answers */
    private function fakeModel(array $answers): FakeModelGateway
    {
        $fake = (new FakeModelGateway)->willAnswer($answers);
        $this->app->instance(ModelGateway::class, $fake);

        return $fake;
    }

    /**
     * A gateway that answers a candidate pool.
     *
     * The Studio writes one call per candidate per channel, so the positional
     * queue cannot script it: a test would have to know that the loop reaches
     * Threads' third angle before Instagram's first. The closure answers by
     * looking at the request instead, and returns null for the proposal role so
     * `$answers` still scripts those in order.
     *
     * @param  list<string>  $answers  the proposal answers, in order
     * @param  (\Closure(ModelRequest): ?string)|null  $draft  overrides the default candidate
     * @param  (\Closure(ModelRequest): ?string)|null  $factCheck  overrides the clean verdict
     */
    private function fakeStudio(
        array $answers = [],
        ?\Closure $draft = null,
        ?\Closure $factCheck = null,
    ): FakeModelGateway {
        $fake = (new FakeModelGateway)
            ->willAnswer($answers === [] ? [$this->proposal()] : $answers)
            ->willAnswerUsing(function (ModelRequest $request) use ($draft, $factCheck): ?string {
                if ($request->role === 'factcheck') {
                    return ($factCheck === null ? null : $factCheck($request)) ?? 'PASS';
                }

                if ($request->role !== 'draft') {
                    return null;
                }

                return ($draft === null ? null : $draft($request)) ?? $this->candidate($request);
            });

        $this->app->instance(ModelGateway::class, $fake);

        return $fake;
    }

    /** Which channel this draft call is for, read off its standing instruction. */
    private function channelOf(ModelRequest $request): string
    {
        foreach (['Instagram', 'Threads', 'X'] as $label) {
            if (str_contains($request->instructions, "posts for this brand on {$label}.")) {
                return strtolower($label);
            }
        }

        throw new \RuntimeException('A draft call named no channel.');
    }

    /**
     * A candidate that is valid for whichever channel asked for it.
     *
     * @param  list<array<string, mixed>>|null  $slides  a carousel's own slides, for the tests about layouts
     */
    private function candidate(ModelRequest $request, ?string $text = null, ?array $slides = null): string
    {
        $channel = $this->channelOf($request);

        if ($channel !== 'instagram') {
            return (string) json_encode([
                'segments' => [$text ?? ($channel === 'threads'
                    ? 'What should a content pipeline explain before you trust it?'
                    : 'A compact X post.')],
                'link' => null,
                'chain_reason' => null,
                'visual' => $this->visual(),
            ]);
        }

        $carousel = str_contains($request->prompt, '"slides"');

        return (string) json_encode(array_filter([
            'caption' => $text ?? 'A trustworthy content engine shows its work.',
            'slides' => $carousel ? ($slides ?? [
                ['heading' => 'The idea', 'body' => 'Decisions should remain inspectable.'],
                ['heading' => 'The mechanism', 'body' => 'Version the brief and the plan.'],
            ]) : null,
            'visual' => $this->visual(),
        ]));
    }

    /**
     * A picture brief a real model answer would survive.
     *
     * It used to be a printed calendar on a desk beside a laptop, which is
     * three of the things {@see VisualBriefGuard} now refuses: a prop that
     * exists to carry words, a screen, and no work in the frame. Left as it
     * was, every draft call in this file spent its first answer being corrected
     * — the tests still passed, and quietly asserted twice the model calls they
     * meant to.
     *
     * @return array<string, string>
     */
    private function visual(): array
    {
        return [
            'subject' => 'a hand drawing a cloth through the dust along the back edge of a shared desk',
            'composition' => 'overhead, the desk edge filling the frame at a slight angle',
            'action' => 'the cloth lifts a clean line through the grime and leaves the rest of it there',
            'location' => 'a shared desk with two cold coffees pushed aside',
            'style' => 'photorealistic editorial photography, unstyled',
            'light' => 'window light from the left, shallow depth of field',
        ];
    }

    /**
     * Posts already published in the four weeks before the month under test.
     *
     * The run-up the proposed cadence is compared against. Dated inside the
     * window rather than merely "in the past": the window closes where the month
     * opens, so a post written for August must not count as the history August
     * is being sized against.
     */
    private function publishedBefore(int $count): void
    {
        app(CurrentProject::class)->run($this->project, function () use ($count): void {
            for ($index = 0; $index < $count; $index++) {
                ContentItem::factory()->create([
                    'type' => ContentItemType::SocialPost,
                    'state' => ContentItemState::Published,
                    'published_at' => Carbon::parse('2026-07-20')->addDays($index),
                ]);
            }
        });
    }

    /**
     * A month the mix accepts.
     *
     * `kinds` is cycled over the ideas. The default covers what ContentMix
     * requires of a four-idea month — at least one how_to, at least one take,
     * and no offer at all — so a test that does not care about the mix does not
     * have to think about it. A test that does care passes its own.
     *
     * @param  list<string>|null  $dates
     * @param  list<string>|null  $kinds
     * @param  array<string, mixed>|false|null  $goal  false omits it entirely
     * @param  list<string>|null  $evidence  what every idea may state as fact
     */
    private function proposal(
        string $summary = 'A practical month about building a trustworthy content engine.',
        string $keyPrefix = 'engine',
        ?array $dates = null,
        ?array $kinds = null,
        array|false|null $goal = null,
        ?array $evidence = null,
    ): string {
        $kinds ??= ['how_to', 'take', 'proof', 'behind'];
        $evidence ??= ['The engine keeps versioned briefs.'];

        $goal ??= [
            'kpi' => 'engagement',
            'target' => 340,
            'cadence' => 4,
            'expected_impact' => 'Four posts a week at the current interaction rate reaches 340 by day 28.',
            'weeks' => [
                'Find the format that earns a reply',
                'Repeat the format that worked',
                'Scale the winner and test one adjacent hook',
                'Write down what the month proved',
            ],
        ];

        return (string) json_encode([
            'summary' => $summary,
            ...($goal === false ? [] : ['goal' => $goal]),
            'site_facts' => [[
                'claim' => 'The product is a GEO-native content engine.',
                'source' => 'site analysis',
            ]],
            'assumptions' => ['The audience wants implementation detail.'],
            'objectives' => ['Build trust before asking for a sale.'],
            'pillars' => [[
                'name' => 'Build in public',
                'purpose' => 'Show decisions and evidence from the work.',
            ]],
            'channel_roles' => [
                'threads' => 'Conversation and observations.',
                'x' => 'Compact arguments and frameworks.',
                'instagram' => 'Visual explanations and stories.',
            ],
            'questions' => ['Which launch dates are not on the site?'],
            'ideas' => array_map(
                static fn (string $date, int $index): array => [
                    'key' => "{$keyPrefix}-{$index}",
                    'date' => $date,
                    'title' => 'Content idea '.$index,
                    'kind' => $kinds[($index - 1) % count($kinds)],
                    'pillar' => 'Build in public',
                    'thesis' => 'A pipeline is useful when its decisions remain inspectable.',
                    'evidence' => $evidence,
                    'goal' => 'trust',
                    'audience' => 'founders',
                    'angle' => 'A concrete implementation lesson.',
                    // Distinct per idea, because a proposal that leaves any
                    // `shot` out is now sent back for correction — a null shot
                    // hands the subject back to the drafting step, which picks
                    // one per post in parallel and blind, and that is the
                    // fallback this whole feature removes.
                    'shot' => "A photograph for idea {$index}: something no other idea in the month shows.",
                    // Asked for greedily on purpose: the engine narrows this to
                    // the channels the kind is native to, and a fixture that
                    // pre-narrowed it would be testing itself.
                    'channels' => ['threads', 'x', 'instagram'],
                ],
                // The first two land in the same week on purpose. No single
                // idea reaches all three channels any more — a how_to goes to
                // Instagram and X, a take to Threads and X — so a batch that
                // exercises every channel needs two ideas in the window.
                $dates ??= ['2026-08-03', '2026-08-04', '2026-08-19', '2026-08-26'],
                // Derived rather than the literal [1, 2, 3, 4] this used to be:
                // a caller passing more dates than that got `null` for every
                // index past the fourth, which surfaces as a TypeError inside
                // the closure rather than as anything about the fixture.
                range(1, count($dates)),
            ),
        ], JSON_UNESCAPED_SLASHES);
    }
}
