<?php

declare(strict_types=1);

namespace Tests\Feature\ContentStudio;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Ai\ModelCatalog;
use App\Enums\ContentItemState;
use App\Enums\ContentPlanStatus;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Models\BrandBrief;
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
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContentStudioTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-09 10:00:00');

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
        $this->get('/studio?month=2026-08')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('studio/index')
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
        $this->get('/studio')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('studio/index')
                ->where('month', '2026-08')
                ->missing('autoPropose')
                ->where('plan', null));

        $this->get('/studio?month=2026-09')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('studio/index')
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
            ->assertJsonPath('plan.ideas.0.channels', ['threads', 'x', 'instagram']);

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
    public function onboarding_can_generate_one_three_channel_preview_before_plan_acceptance(): void
    {
        $this->fakeModel([$this->proposal(), $this->drafts()]);

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
            $this->assertCount(3, $items);
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
        $this->fakeModel([
            $this->proposal(),
            $this->drafts(),
            $this->proposal('The remaining month now focuses on operator lessons.'),
            $this->drafts(),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');

        $this->postJson("/studio/plans/{$planId}/generate")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Accept the current proposal before generating drafts.');

        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();

        $this->postJson("/studio/plans/{$planId}/generate")
            ->assertStatus(202)
            ->assertJsonPath('operation.result.created', 3)
            ->assertJsonPath('operation.result.from', '2026-08-03')
            ->assertJsonPath('operation.result.until', '2026-08-09')
            ->assertJsonCount(3, 'plan.ideas.0.drafts')
            ->assertJsonCount(1, 'plan.ideas.0.drafts.0.assets')
            ->assertJsonCount(0, 'plan.ideas.1.drafts');

        app(CurrentProject::class)->run($this->project, function (): void {
            $items = ContentItem::query()->orderBy('channel_type')->get();

            $this->assertCount(3, $items);
            $this->assertSame(
                ['instagram', 'threads', 'x'],
                $items->pluck('channel_type')->all(),
            );
            $this->assertTrue($items->every(
                static fn (ContentItem $item): bool => $item->state === ContentItemState::Draft,
            ));
            $this->assertSame('image', $items->first()->channel_payload['format']);
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
            ->assertJsonCount(3, 'plan.ideas.0.drafts');

        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 2])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")
            ->assertStatus(202)
            ->assertJsonPath('operation.result.created', 3)
            ->assertJsonCount(3, 'plan.ideas.0.drafts')
            ->assertJsonCount(3, 'plan.ideas.1.drafts');

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(6, ContentItem::query()->count());
            $this->assertSame(4, PipelineRun::query()->count());
            $this->assertSame(2, PipelineRun::query()->where('input->action', 'generate_week')->count());
            $this->assertSame(
                'carousel',
                ContentItem::query()
                    ->where('channel_type', 'instagram')
                    ->whereDate('scheduled_for', '2026-08-12')
                    ->firstOrFail()
                    ->channel_payload['format'],
            );
        });
    }

    #[Test]
    public function unexpected_provider_errors_are_not_exposed_to_the_browser(): void
    {
        $fake = (new FakeModelGateway)->willThrow(
            static fn (): \RuntimeException => new \RuntimeException('secret upstream details'),
        );
        $this->app->instance(ModelGateway::class, $fake);

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
        $tooLong = $this->drafts(str_repeat('x', 281));
        $fake = $this->fakeModel([
            $this->proposal(),
            $tooLong,
            $this->drafts(),
        ]);

        $planId = $this->postJson('/studio/propose', ['month' => '2026-08'])
            ->json('plan.id');
        $this->postJson("/studio/plans/{$planId}/accept", ['version' => 1])->assertOk();
        $this->postJson("/studio/plans/{$planId}/generate")
            ->assertStatus(202)
            ->assertJsonPath('operation.result.created', 3);

        $this->assertSame(3, $fake->callCount());
        $this->assertStringContainsString(
            'exceeds 280 characters',
            $fake->lastRequest()->prompt,
        );

        app(CurrentProject::class)->run($this->project, function (): void {
            $x = ContentItem::query()->where('channel_type', 'x')->firstOrFail();
            $run = PipelineRun::query()->where('input->action', 'generate_week')->firstOrFail();
            $step = $run->steps()->firstOrFail();

            $this->assertSame('A compact X post.', $x->channel_payload['segments'][0]['text']);
            $this->assertGreaterThan(0, $step->cost_micros);
        });
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

    /** @param list<string> $answers */
    private function fakeModel(array $answers): FakeModelGateway
    {
        $fake = (new FakeModelGateway)->willAnswer($answers);
        $this->app->instance(ModelGateway::class, $fake);

        return $fake;
    }

    private function proposal(
        string $summary = 'A practical month about building a trustworthy content engine.',
        string $keyPrefix = 'engine',
    ): string {
        return (string) json_encode([
            'summary' => $summary,
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
                    'pillar' => 'Build in public',
                    'thesis' => 'A pipeline is useful when its decisions remain inspectable.',
                    'evidence' => ['The engine keeps versioned briefs.'],
                    'goal' => 'trust',
                    'audience' => 'founders',
                    'angle' => 'A concrete implementation lesson.',
                    'channels' => ['threads', 'x', 'instagram'],
                ],
                ['2026-08-03', '2026-08-12', '2026-08-19', '2026-08-26'],
                [1, 2, 3, 4],
            ),
        ], JSON_UNESCAPED_SLASHES);
    }

    private function drafts(string $x = 'A compact X post.'): string
    {
        return (string) json_encode([
            'threads' => [
                'format' => 'post',
                'segments' => ['What should a content pipeline explain before you trust it?'],
            ],
            'x' => [
                'format' => 'post',
                'segments' => [$x],
            ],
            'instagram' => [
                'format' => 'carousel',
                'caption' => 'A trustworthy content engine shows its work.',
                'slides' => [
                    ['heading' => 'The idea', 'body' => 'Decisions should remain inspectable.'],
                    ['heading' => 'The mechanism', 'body' => 'Version the brief and the plan.'],
                ],
                'visual_brief' => 'Simple editorial diagrams on a warm neutral background.',
            ],
        ]);
    }
}
