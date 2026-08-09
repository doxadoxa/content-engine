<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\ChannelType;
use App\Enums\PipelineRunStatus;
use App\Models\Asset;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Interaction;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectState;
use App\Models\Signal;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exit criterion 4 of phase 2: every entity has a factory, because from here on
 * tests are not written without them.
 *
 * "Has a factory" is checked as "produces a persisted row in the current
 * tenant" rather than "a class exists": a factory that needs three arguments
 * before it works is one every later test will route around.
 */
final class FactoriesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);
    }

    /**
     * Spelled out call by call rather than driven from a list of class names:
     * `factory()` comes from the HasFactory trait, so a `class-string<Model>`
     * cannot be shown to have it and every generic signature that tries ends up
     * fighting the invariance of `Factory<T>`. Literal calls type themselves.
     */
    #[Test]
    public function every_entity_has_a_factory_that_builds_a_row_in_the_current_project(): void
    {
        $rows = [
            BrandBrief::class => BrandBrief::factory()->createOne(),
            Channel::class => Channel::factory()->createOne(),
            ContentPlan::class => ContentPlan::factory()->createOne(),
            ContentItem::class => ContentItem::factory()->createOne(),
            Asset::class => Asset::factory()->createOne(),
            PipelineRun::class => PipelineRun::factory()->createOne(),
            PipelineStep::class => PipelineStep::factory()->createOne(),
            WebhookDelivery::class => WebhookDelivery::factory()->createOne(),
            Signal::class => Signal::factory()->createOne(),
            Interaction::class => Interaction::factory()->createOne(),
            ProjectState::class => ProjectState::factory()->createOne(),
        ];

        foreach ($rows as $class => $row) {
            $this->assertTrue($row->exists, "{$class} did not persist a row.");
            $this->assertSame(
                $this->project->getKey(),
                $row->getAttribute('project_id'),
                "{$class} was not stamped with the current project.",
            );
        }
    }

    #[Test]
    public function every_factory_builds_several_rows_at_once(): void
    {
        // Uniqueness constraints (slug, channel name, plan month, brief
        // version) are the reason this is worth asserting separately: a factory
        // that works once and collides on the second row is useless for the
        // list screens every later phase is made of.
        $batches = [
            BrandBrief::class => BrandBrief::factory()->createMany(3),
            Channel::class => Channel::factory()->createMany(3),
            ContentPlan::class => ContentPlan::factory()->createMany(3),
            ContentItem::class => ContentItem::factory()->createMany(3),
            Asset::class => Asset::factory()->createMany(3),
            PipelineRun::class => PipelineRun::factory()->createMany(3),
            PipelineStep::class => PipelineStep::factory()->createMany(3),
            WebhookDelivery::class => WebhookDelivery::factory()->createMany(3),
            // The three §3 tables have uniqueness of their own, and
            // `project_states` is the sharpest case on this list: one row per
            // project per day, so its factory hands out consecutive days from a
            // per-instance counter. A fixed date would collide on the second
            // row, which is exactly what this test is for.
            Signal::class => Signal::factory()->createMany(3),
            Interaction::class => Interaction::factory()->createMany(3),
            ProjectState::class => ProjectState::factory()->createMany(3),
        ];

        foreach ($batches as $class => $batch) {
            $this->assertCount(3, $batch, "{$class} could not make three rows.");
        }
    }

    #[Test]
    public function a_factory_outside_a_tenant_makes_its_own_project(): void
    {
        app(CurrentProject::class)->forget();

        $item = ContentItem::factory()->create();

        $this->assertNotSame('', $item->project_id);
        $this->assertNotSame($this->project->getKey(), $item->project_id);
    }

    #[Test]
    public function a_batch_with_no_tenant_gives_each_project_a_clean_history(): void
    {
        app(CurrentProject::class)->forget();

        // With no tenant every row mints its own project, so this is three
        // projects with one brief each — not one project with three versions.
        // A factory counting rows without noticing that hands the second
        // project a brief numbered v2 and flagged superseded, leaving it with
        // a history it never had and nothing live.
        $briefs = BrandBrief::factory()->createMany(3);

        $this->assertSame(3, $briefs->pluck('project_id')->unique()->count());

        foreach ($briefs as $brief) {
            $this->assertSame(1, $brief->version);
            $this->assertTrue($brief->is_active, 'A fresh project has no live brief.');
        }

        $plans = ContentPlan::factory()->createMany(3);
        $months = $plans->map(fn (ContentPlan $plan): string => $plan->month->toDateString());

        $this->assertSame(3, $plans->pluck('project_id')->unique()->count());
        $this->assertSame(1, $months->unique()->count(), 'Each project should start its calendar in the same month.');
    }

    #[Test]
    public function a_child_factory_lands_in_the_same_project_as_its_parent(): void
    {
        app(CurrentProject::class)->forget();

        // No tenant, so the asset cannot resolve one — it has to take its
        // unit's. Resolving separately would put an image in a project its own
        // article is not in.
        $asset = Asset::factory()->create();

        // Read across projects: with no tenant current, the scope fails closed
        // and `$asset->contentItem` is null. That is the phase 1 guarantee
        // working, not a missing row.
        $item = ContentItem::acrossProjects()->findOrFail($asset->content_item_id);

        $this->assertSame($item->project_id, $asset->project_id);

        $delivery = WebhookDelivery::factory()->create();
        $channel = Channel::acrossProjects()->findOrFail($delivery->channel_id);

        $this->assertSame($channel->project_id, $delivery->project_id);
    }

    #[Test]
    public function the_content_item_factory_states_produce_what_they_claim(): void
    {
        $this->assertTrue(ContentItem::factory()->draft()->create()->state->value === 'draft');

        $published = ContentItem::factory()->published()->create();
        $this->assertTrue($published->state->isLive());
        $this->assertNotNull($published->published_at);

        $this->assertSame('pt-PT', ContentItem::factory()->locale('pt-PT')->create()->locale);
    }

    #[Test]
    public function the_channel_factory_states_produce_what_they_claim(): void
    {
        $this->assertTrue(Channel::factory()->webhook()->create()->type === ChannelType::Webhook);
        $this->assertTrue(Channel::factory()->social()->create()->type->isSocial());
        $this->assertFalse(Channel::factory()->withoutSecret()->create()->hasSecret());
        $this->assertFalse(Channel::factory()->disabled()->create()->is_enabled);
    }

    #[Test]
    public function the_plan_factory_states_produce_what_they_claim(): void
    {
        $draft = ContentPlan::factory()->create();
        $this->assertFalse($draft->isApproved());

        $approved = ContentPlan::factory()->forMonth('2027-01-10')->approved()->create();
        $this->assertTrue($approved->isApproved());
        $this->assertSame('2027-01-01', $approved->month->toDateString());
    }

    #[Test]
    public function the_run_and_delivery_factories_carry_their_metering_and_payload(): void
    {
        $run = PipelineRun::factory()->create();
        $this->assertSame($run->input_tokens + $run->output_tokens, $run->totalTokens());

        // Phase 3 moved the steps out of a json column and onto their own
        // table, so a bare run has no step rows until one is given some.
        $this->assertSame(0, $run->steps()->count());

        $step = PipelineStep::factory()->for($run, 'pipelineRun')->create();
        $this->assertSame($run->getKey(), $step->pipeline_run_id);
        $this->assertSame($run->project_id, $step->project_id);
        $this->assertSame(1, $run->steps()->count());

        $this->assertNull(PipelineRun::factory()->running()->create()->finished_at);

        $failed = PipelineRun::factory()->failed()->create();
        $this->assertSame(PipelineRunStatus::Failed, $failed->status);
        $this->assertNotNull($failed->failed_step_key);

        $delivery = WebhookDelivery::factory()->create();
        $this->assertNotSame('', $delivery->delivery_id);
        $this->assertNotSame([], $delivery->payload_snapshot);

        $this->assertNotNull(WebhookDelivery::factory()->failed()->create()->next_attempt_at);
    }

    #[Test]
    public function a_delivery_gets_an_idempotency_key_it_was_not_given(): void
    {
        $delivery = WebhookDelivery::factory()->create(['delivery_id' => null]);

        // Phase 6 dedupes on this. A row without one is a delivery the
        // receiver cannot recognise as a repeat.
        $this->assertNotSame('', $delivery->delivery_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $delivery->delivery_id);
    }

    #[Test]
    public function a_content_item_gets_a_locale_group_it_was_not_given(): void
    {
        $item = ContentItem::factory()->create(['locale_group_id' => null]);

        $this->assertNotSame('', $item->locale_group_id);
        $this->assertSame($item->locale_group_id, $item->fresh()?->locale_group_id);
    }
}
