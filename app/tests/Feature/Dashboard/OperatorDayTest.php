<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\RejectionReason;
use App\Enums\WebhookEvent;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\WebhookPublisher;
use App\Support\Content\InvalidStateTransition;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 7's exit criteria: the operator runs the whole day from the panel.
 *
 * Criterion 1 is a sequence rather than a screen — queue → card → approve →
 * visible in the delivery log — so it is tested as one.
 */
final class OperatorDayTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $operator;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->operator = User::factory()->create();
        $this->operator->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->set($this->project);

        $this->channel = Channel::factory()->create([
            'type' => ChannelType::Webhook,
            'config' => ['endpoint' => 'https://receiver.test/engine/webhook'],
            'secret' => 'shared-secret',
        ]);

        config()->set('queue.default', 'sync');
    }

    #[Test]
    public function the_article_card_exposes_every_language_and_its_state(): void
    {
        $draft = $this->draft(['locale' => 'en']);
        $portuguese = $draft->addLocale(
            'pt-PT',
            'como-limpar-janelas',
            'Como limpar janelas',
        );
        $portuguese->forceFill(['state' => ContentItemState::Approved])->save();

        $this->actingAs($this->operator)
            ->get(route('content.show', $draft))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('locales', 2)
                ->where('locales.0.locale', 'en')
                ->where('locales.0.state_label', 'Draft')
                ->where('locales.0.is_self', true)
                ->where('locales.1.locale', 'pt-PT')
                ->where('locales.1.state_label', 'Approved')
                ->where('locales.1.is_self', false)
            );
    }

    // ------------------------------------------------------- exit criterion 1

    #[Test]
    public function an_operator_runs_the_day_from_the_panel(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['public_url' => 'https://example.test/x'])]);

        $this->channel->forceFill(['autopublish' => true, 'verified_at' => now()])->save();

        $draft = $this->draft();

        // The queue.
        $this->actingAs($this->operator)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('approvals/index')
                ->has('drafts.data', 1)
                ->where('drafts.data.0.id', $draft->getKey())
            );

        // The card.
        $this->actingAs($this->operator)
            ->get(route('content.show', $draft))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('content/show')
                ->where('item.title', 'How to clean windows')
                ->has('deliveries')
            );

        // The approval.
        $this->actingAs($this->operator)
            ->post(route('content.approve', $draft))
            ->assertRedirect();

        // Published, because a channel on this project auto-publishes.
        $this->assertSame(ContentItemState::Published, $draft->refresh()->state);

        // And visible in the delivery log.
        $this->actingAs($this->operator)
            ->get(route('deliveries.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('deliveries/index')
                ->has('deliveries.data', 1)
                ->where('deliveries.data.0.status', 'delivered')
            );
    }

    #[Test]
    public function approving_without_autopublish_stops_at_approved(): void
    {
        Http::fake();

        $draft = $this->draft();

        $this->actingAs($this->operator)->post(route('content.approve', $draft));

        // §5.4: auto-publish is a privilege a channel earns, and this one has
        // not. Approval means "fit to publish", not "publish now".
        $this->assertSame(ContentItemState::Approved, $draft->refresh()->state);
        Http::assertNothingSent();
    }

    #[Test]
    public function automatic_publishing_targets_only_verified_automatic_channels(): void
    {
        Http::fake([
            'receiver.test/*' => Http::response(['public_url' => 'https://example.test/x']),
            'manual.test/*' => Http::response(['public_url' => 'https://example.test/x']),
        ]);

        $this->channel->forceFill(['autopublish' => true, 'verified_at' => now()])->save();

        $manual = Channel::factory()->create([
            'type' => ChannelType::Webhook,
            'name' => 'Manual destination',
            'config' => ['endpoint' => 'https://manual.test/hook'],
            'secret' => 'manual-secret',
            'autopublish' => false,
            'verified_at' => now(),
        ]);

        $draft = $this->draft();

        $this->actingAs($this->operator)->post(route('content.approve', $draft));

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame($this->channel->getKey(), $delivery->channel_id);
        $this->assertNotSame($manual->getKey(), $delivery->channel_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'manual.test'));
    }

    #[Test]
    public function a_manual_channel_can_receive_content_after_another_channel_made_it_live(): void
    {
        Http::fake([
            'receiver.test/*' => Http::response(['public_url' => 'https://example.test/x']),
            'manual.test/*' => Http::response(['public_url' => 'https://example.test/x']),
        ]);

        $this->channel->forceFill(['autopublish' => true, 'verified_at' => now()])->save();
        $manual = Channel::factory()->create([
            'type' => ChannelType::Webhook,
            'name' => 'Manual destination',
            'config' => ['endpoint' => 'https://manual.test/hook'],
            'secret' => 'manual-secret',
            'autopublish' => false,
            'verified_at' => now(),
        ]);

        $draft = $this->draft();
        $this->actingAs($this->operator)->post(route('content.approve', $draft));
        $this->assertSame(ContentItemState::Published, $draft->refresh()->state);

        $this->actingAs($this->operator)
            ->post(route('content.publish', $draft))
            ->assertRedirect();

        $this->assertSame(2, WebhookDelivery::query()->count());
        $manualDelivery = WebhookDelivery::query()->where('channel_id', $manual->getKey())->sole();
        $this->assertSame(WebhookEvent::Published->value, $manualDelivery->payload_snapshot['event']);
        $this->assertSame(
            1,
            WebhookDelivery::query()->where('channel_id', $this->channel->getKey())->count(),
        );
    }

    #[Test]
    public function an_unpublishable_draft_cannot_be_approved(): void
    {
        $draft = $this->draft([
            'body_markdown' => '## Generic advice'."\n\n".'This is a simple and seamless solution.',
        ]);

        $this->actingAs($this->operator)
            ->post(route('content.approve', $draft))
            ->assertSessionHasErrors('approval');

        $this->assertSame(ContentItemState::Draft, $draft->refresh()->state);
    }

    #[Test]
    public function the_queue_surfaces_the_reasons_not_to_approve(): void
    {
        $this->draft([
            'slug' => 'suspect-draft',
            'entities' => ['Lisbon', 'Porto'],
            'entity_coverage' => ['Lisbon' => true, 'Porto' => false],
            'factcheck' => ['passed' => false, 'findings' => ['no source'], 'required' => false],
        ]);

        $this->actingAs($this->operator)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Found on the row rather than one click in: a queue where you
                // must open each item is a queue nobody clears.
                ->where('drafts.data.0.factcheck_passed', false)
                ->where('drafts.data.0.factcheck_findings', 1)
                ->where('drafts.data.0.entity_coverage', 0.5)
            );
    }

    #[Test]
    public function only_a_draft_can_be_approved(): void
    {
        $unit = ContentItem::factory()->published()->create();

        $this->actingAs($this->operator)
            ->post(route('content.approve', $unit))
            ->assertStatus(409);
    }

    // --------------------------------------------------------------- reject

    #[Test]
    public function a_rejection_records_a_structured_reason(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->operator)
            ->post(route('content.reject', $draft), [
                'reason' => RejectionReason::OffBrand->value,
                'note' => 'Reads like a brochure.',
            ])
            ->assertRedirect();

        $draft->refresh();

        // §7: the reason is the input to phase 9's quality loop, so it is a
        // code that can be counted rather than a sentence in a log.
        $this->assertSame('off_brand', $draft->review['reason']);
        $this->assertSame('Reads like a brochure.', $draft->review['note']);
        $this->assertNotNull($draft->reviewed_at);

        // Still a draft: a rejection is a note for whoever rewrites it, and
        // there is no edge out of `draft` meaning "worse than before".
        $this->assertSame(ContentItemState::Draft, $draft->state);
    }

    #[Test]
    public function a_rejection_without_a_reason_is_refused(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->operator)
            ->from(route('approvals.index'))
            ->post(route('content.reject', $draft), ['note' => 'just because'])
            ->assertSessionHasErrors('reason');

        $this->assertNull($draft->refresh()->reviewed_at);
    }

    // ---------------------------------------------- sending an approval back

    #[Test]
    public function an_approved_article_can_be_sent_back_for_rework(): void
    {
        $unit = $this->draft();

        $this->actingAs($this->operator)->post(route('content.approve', $unit))->assertRedirect();
        $this->assertSame(ContentItemState::Approved, $unit->refresh()->state);

        // The case the queue had no answer for. `approved` has one edge and it
        // points at `published`, so an article signed off with a fault in it
        // could be published or ignored — there was no third option, and the
        // operator who found the fault was the one person who could not act on
        // it.
        $this->actingAs($this->operator)
            ->post(route('content.reject', $unit), [
                'reason' => RejectionReason::Inaccurate->value,
                'note' => 'The section on solvents is wrong.',
            ])
            ->assertRedirect();

        $unit->refresh();

        // Back where rework happens, carrying the same structured reason a
        // draft rejection carries — §7 counts them together because they are
        // the same complaint.
        $this->assertSame(ContentItemState::Draft, $unit->state);
        $this->assertSame('inaccurate', $unit->review['reason']);
        $this->assertSame('The section on solvents is wrong.', $unit->review['note']);
        $this->assertNotNull($unit->reviewed_at);
    }

    #[Test]
    public function sending_an_article_back_starts_a_rewrite_that_can_see_the_complaint(): void
    {
        Queue::fake();

        $unit = $this->draft();

        $this->actingAs($this->operator)->post(route('content.approve', $unit))->assertRedirect();

        $this->actingAs($this->operator)
            ->post(route('content.reject', $unit), [
                'reason' => RejectionReason::TooThin->value,
                'note' => 'Two sentences on pricing is not a section.',
            ])
            ->assertRedirect();

        // Sending back has to *cause* something. Without this the button
        // un-approved an article and left it word for word as it was: the
        // operator had said what was wrong and the only thing that could act on
        // it was a human rewriting by hand.
        $this->assertTrue(
            PipelineRun::acrossProjects()
                ->where('pipeline', 'generation')
                ->where('content_item_id', $unit->getKey())
                ->exists(),
            'Sending an article back should hand it to the engine.',
        );
    }

    #[Test]
    public function an_off_brand_rejection_waits_for_a_human_instead_of_rewriting(): void
    {
        Queue::fake();

        $unit = $this->draft();

        $this->actingAs($this->operator)->post(route('content.approve', $unit))->assertRedirect();

        $this->actingAs($this->operator)
            ->post(route('content.reject', $unit), ['reason' => RejectionReason::OffBrand->value])
            ->assertRedirect();

        // §7 and phase 9 both say it: a project whose rejections are mostly
        // off-brand has a brief problem, and regenerating the same article from
        // the same brief produces the same article at full price.
        $this->assertSame(ContentItemState::Draft, $unit->refresh()->state);

        $this->assertFalse(
            PipelineRun::acrossProjects()
                ->where('pipeline', 'generation')
                ->where('content_item_id', $unit->getKey())
                ->exists(),
        );
    }

    #[Test]
    public function approving_clears_the_complaint(): void
    {
        Queue::fake();

        $unit = $this->draft();

        $this->actingAs($this->operator)->post(route('content.approve', $unit))->assertRedirect();
        $this->actingAs($this->operator)
            ->post(route('content.reject', $unit), ['reason' => RejectionReason::TooThin->value])
            ->assertRedirect();

        $this->assertNotNull($unit->refresh()->reviewed_at);

        $this->actingAs($this->operator)->post(route('content.approve', $unit))->assertRedirect();

        // Left standing it would be read by every future rewrite and shown on
        // the card as an objection to an article nobody objects to any more.
        $this->assertNull($unit->refresh()->reviewed_at);
        $this->assertSame([], $unit->review);
    }

    #[Test]
    public function sending_back_cancels_a_publication_that_was_already_queued(): void
    {
        Queue::fake();

        $unit = $this->draft();

        $this->actingAs($this->operator)->post(route('content.approve', $unit))->assertRedirect();

        // Approving may already have queued this unit at every channel that
        // publishes automatically, and a delivery sends the payload it captured
        // whatever the unit has done since.
        $delivery = WebhookDelivery::factory()->create([
            'content_item_id' => $unit->getKey(),
            'status' => DeliveryStatus::Pending,
        ]);

        $this->actingAs($this->operator)
            ->post(route('content.reject', $unit), ['reason' => RejectionReason::Inaccurate->value])
            ->assertRedirect();

        // Otherwise the operator pulls an inaccurate article and the version
        // they pulled goes out a minute later.
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
        $this->assertStringContainsString('sent back for rework', (string) $delivery->error);
    }

    #[Test]
    public function a_published_article_is_not_sent_back(): void
    {
        $unit = ContentItem::factory()->published()->create();

        // Text somebody may have linked to is not withdrawn by an approvals
        // screen. That is what `refreshing` is for.
        $this->actingAs($this->operator)
            ->post(route('content.reject', $unit), ['reason' => RejectionReason::Inaccurate->value])
            ->assertStatus(409);

        $this->assertSame(ContentItemState::Published, $unit->refresh()->state);
    }

    #[Test]
    public function the_engine_still_cannot_unapprove_an_article_by_accident(): void
    {
        $unit = $this->draft();

        $this->actingAs($this->operator)->post(route('content.approve', $unit))->assertRedirect();

        // `finalise_draft` ends by moving a unit to `draft`, and on 2026-08-09
        // a regeneration of an approved article tried exactly that. The state
        // map refusing is what stopped a human's approval being thrown away
        // silently, so sending back is a named method rather than a new edge —
        // widening the map would have removed the guard along with the
        // inconvenience.
        $this->expectException(InvalidStateTransition::class);

        $unit->refresh()->markDrafted();
    }

    // ------------------------------------------------------------- calendar

    #[Test]
    public function the_calendar_opens_on_the_month_that_has_something_in_it(): void
    {
        // The planner fills the month *ahead*, so on any day before the first
        // of it the default lands on an empty grid — which reads as an engine
        // that produced nothing, next to a Content list plainly full of work.
        $this->draft(['scheduled_for' => now()->addMonth()->startOfMonth()->addDays(6)]);

        $this->actingAs($this->operator)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('month', now()->addMonth()->startOfMonth()->toDateString())
                ->has('units', 1)
                ->etc()
            );
    }

    #[Test]
    public function the_calendar_stays_on_this_month_when_this_month_has_work(): void
    {
        $this->draft(['scheduled_for' => now()->startOfMonth()->addDays(3)]);

        $this->actingAs($this->operator)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('month', now()->startOfMonth()->toDateString())
                ->etc()
            );
    }

    #[Test]
    public function the_calendar_shows_the_month_it_was_asked_for(): void
    {
        $this->draft(['scheduled_for' => '2026-09-14', 'slug' => 'scheduled-one']);

        $this->actingAs($this->operator)
            ->get(route('calendar.index', ['month' => '2026-09-01']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('calendar/index')
                ->where('label', 'September 2026')
                ->where('days_in_month', 30)
                // 1 September 2026 is a Tuesday.
                ->where('starts_on', 2)
                ->has('units', 1)
            );
    }

    #[Test]
    public function the_calendar_rejects_malformed_or_impossible_months(): void
    {
        $this->actingAs($this->operator)
            ->get(route('calendar.index', ['month' => 'next Thursday']))
            ->assertBadRequest();

        $this->actingAs($this->operator)
            ->get(route('calendar.index', ['month' => '2026-02-31']))
            ->assertBadRequest();
    }

    #[Test]
    public function the_calendar_lists_undated_ideas_separately(): void
    {
        ContentItem::factory()->create(['scheduled_for' => null, 'slug' => 'no-date']);

        $this->actingAs($this->operator)
            ->get(route('calendar.index', ['month' => '2026-09-01']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('units', 0)
                // Dropping them would hide exactly what an operator looks for
                // when a month looks thin.
                ->has('unscheduled', 1)
            );
    }

    // ------------------------------------------------------- exit criterion 2

    #[Test]
    public function a_channel_is_connected_through_the_wizard_without_the_console(): void
    {
        Http::fake(['newsite.test/*' => Http::response(['public_url' => 'https://newsite.test/x'])]);

        // No content anywhere: connecting a channel is something you do on a
        // fresh project, before anything has been written.
        $this->assertSame(0, ContentItem::query()->count());

        $this->actingAs($this->operator)
            ->post(route('channels.store'), [
                'name' => 'New blog',
                'type' => ChannelType::Webhook->value,
                'config' => ['endpoint' => 'https://newsite.test/engine/webhook'],
                'secret' => 'a-new-secret',
            ])
            ->assertRedirect(route('channels.index'));

        $channel = Channel::query()->where('name', 'New blog')->firstOrFail();

        // Configured, but not yet proven.
        $this->assertNull($channel->verified_at);

        $this->actingAs($this->operator)
            ->post(route('channels.ping', $channel))
            ->assertRedirect();

        // A real signed ping came back, so it is connected on evidence.
        $this->assertNotNull($channel->refresh()->verified_at);

        Http::assertSent(fn ($request): bool => $request->header('X-Engine-Event')[0] === 'ping');

        // A ping is not about a unit, so it is not attached to one.
        $this->assertNull(WebhookDelivery::query()->latest()->firstOrFail()->content_item_id);
    }

    #[Test]
    public function an_asynchronous_ping_verifies_only_after_its_job_succeeds(): void
    {
        Queue::fake();
        Http::fake(['receiver.test/*' => Http::response([])]);

        $this->actingAs($this->operator)
            ->post(route('channels.ping', $this->channel))
            ->assertRedirect();

        $this->assertNull($this->channel->refresh()->verified_at);

        $job = null;
        Queue::assertPushed(DeliverWebhookJob::class, function (DeliverWebhookJob $queued) use (&$job): bool {
            $job = $queued;

            return true;
        });

        $this->assertInstanceOf(DeliverWebhookJob::class, $job);
        $job->handle(app(ChannelPublisherRegistry::class), app(CurrentProject::class));

        $this->assertNotNull($this->channel->refresh()->verified_at);
    }

    #[Test]
    public function a_ping_that_is_refused_does_not_mark_the_channel_connected(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['error' => 'Bad signature.'], 401)]);

        $this->actingAs($this->operator)->post(route('channels.ping', $this->channel));

        $this->assertNull($this->channel->refresh()->verified_at);
    }

    #[Test]
    public function a_channels_secret_survives_an_edit_that_leaves_it_blank(): void
    {
        $this->channel->forceFill(['verified_at' => now()])->save();

        $this->actingAs($this->operator)
            ->patch(route('channels.update', $this->channel), [
                'name' => $this->channel->name,
                'type' => $this->channel->type->value,
                'config' => $this->channel->config,
                'autopublish' => true,
                'is_enabled' => true,
            ])
            ->assertRedirect();

        $this->channel->refresh();

        // Toggling auto-publish should not require re-pasting a token nobody
        // can read back out of the form.
        $this->assertTrue($this->channel->autopublish);
        $this->assertSame('shared-secret', $this->channel->secret);
    }

    #[Test]
    public function changing_connection_details_clears_the_old_verification(): void
    {
        $this->channel->forceFill(['verified_at' => now()])->save();

        $this->actingAs($this->operator)
            ->patch(route('channels.update', $this->channel), [
                'name' => $this->channel->name,
                'type' => $this->channel->type->value,
                'config' => ['endpoint' => 'https://changed.test/hook'],
                'is_enabled' => true,
                'autopublish' => false,
            ])
            ->assertRedirect();

        $this->assertNull($this->channel->refresh()->verified_at);
    }

    #[Test]
    public function automatic_delivery_cannot_be_enabled_before_verification(): void
    {
        $this->actingAs($this->operator)
            ->patch(route('channels.autopublish', $this->channel))
            ->assertStatus(409);

        $this->channel->forceFill(['verified_at' => now()])->save();

        $this->actingAs($this->operator)
            ->patch(route('channels.autopublish', $this->channel))
            ->assertRedirect();

        $this->assertTrue($this->channel->refresh()->autopublish);
    }

    // ------------------------------------------------------- exit criterion 3

    #[Test]
    public function a_dead_letter_is_replayed_from_the_panel(): void
    {
        Http::fake(['receiver.test/*' => Http::sequence()
            ->push([], 503)->push([], 503)->push([], 503)->push([], 503)->push([], 503)
            ->push(['public_url' => 'https://example.test/x'], 200),
        ]);

        // Approved, because that is the only way a delivery comes to exist —
        // and since sending back for rework, the publisher refuses one whose
        // unit never got past draft.
        $unit = $this->draft();
        $unit->forceFill(['state' => ContentItemState::Approved])->save();

        $dead = app(WebhookPublisher::class)
            ->queue($unit, $this->channel, WebhookEvent::Published)
            ->refresh();

        $this->assertSame(DeliveryStatus::DeadLetter, $dead->status);

        $this->actingAs($this->operator)
            ->get(route('deliveries.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dead_letters', 1)
                ->where('deliveries.data.0.can_replay', true)
            );

        $this->actingAs($this->operator)
            ->post(route('deliveries.replay', $dead))
            ->assertRedirect();

        $this->assertSame('https://example.test/x', $unit->refresh()->public_url);
        $this->assertSame(2, WebhookDelivery::query()->count());
    }

    // ------------------------------------------------------- exit criterion 4

    #[Test]
    public function the_cost_of_an_article_is_on_a_screen(): void
    {
        $unit = $this->draft();

        $run = PipelineRun::factory()->create([
            'pipeline' => 'generation',
            'content_item_id' => $unit->getKey(),
            'cost_micros' => 250_000,
        ]);

        PipelineStep::factory()->for($run, 'pipelineRun')->create([
            'step_key' => 'write_draft',
            'cost_micros' => 250_000,
        ]);

        $this->actingAs($this->operator)
            ->get(route('metering.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('metering/index')
                // The Phase 0 exit criterion names this number by name.
                ->where('per_unit.units', 1)
                ->where('per_unit.average_micros', 250_000)
                ->where('by_step.0.step_key', 'write_draft')
                ->has('by_pipeline', 1)
                ->has('trend', 1)
            );
    }

    #[Test]
    public function project_costs_are_visible_only_to_an_owner(): void
    {
        $member = User::factory()->create();
        $member->projects()->attach($this->project, ['role' => 'operator']);

        $this->actingAs($member)
            ->get(route('metering.index'))
            ->assertForbidden();
    }

    #[Test]
    public function operational_queues_expose_navigation_past_the_first_page(): void
    {
        ContentItem::factory()->count(21)->draft()->create();
        WebhookDelivery::factory()->count(51)->create([
            'channel_id' => $this->channel->getKey(),
        ]);

        $this->actingAs($this->operator)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('drafts.data', 20)
                ->where('drafts.total', 21)
                ->where('drafts.last_page', 2)
            );

        $this->actingAs($this->operator)
            ->get(route('deliveries.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('deliveries.data', 50)
                ->where('deliveries.total', 51)
                ->where('deliveries.last_page', 2)
            );
    }

    #[Test]
    public function pull_channels_require_a_unique_bearer_token(): void
    {
        $this->actingAs($this->operator)->post(route('channels.store'), [
            'name' => 'Missing token',
            'type' => 'pull_api',
            'config' => [],
        ])->assertSessionHasErrors('secret');

        $this->actingAs($this->operator)->post(route('channels.store'), [
            'name' => 'First pull',
            'type' => 'pull_api',
            'config' => [],
            'secret' => 'one-shared-secret',
        ])->assertRedirect(route('channels.index'));

        $this->actingAs($this->operator)->post(route('channels.store'), [
            'name' => 'Second pull',
            'type' => 'pull_api',
            'config' => [],
            'secret' => 'one-shared-secret',
        ])->assertSessionHasErrors('secret');

        $this->assertSame(1, Channel::query()->where('type', ChannelType::PullApi)->count());
    }

    #[Test]
    public function every_operator_screen_needs_a_signed_in_user(): void
    {
        foreach (['approvals.index', 'calendar.index', 'deliveries.index', 'metering.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    #[Test]
    public function the_screens_are_scoped_to_the_current_project(): void
    {
        $this->draft(['slug' => 'mine']);

        $other = Project::factory()->create();
        app(CurrentProject::class)->run($other, function (): void {
            ContentItem::factory()->draft()->create(['slug' => 'theirs']);
        });

        $this->actingAs($this->operator)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('drafts.data', 1));
    }

    /** @param array<string, mixed> $attributes */
    private function draft(array $attributes = []): ContentItem
    {
        $body = "## Where a weekly clean is the wrong call\n\n"
            .'A deep clean takes about three hours. Bathrooms take longest. We bring our own '
            .'cloths and sprays. If you have marble, say so first, because it needs a '
            .'pH-neutral product and most supermarket sprays will etch the surface beyond '
            ."repair.\n\n"
            ."## What a visit covers\n\n"
            ."Most flats need one visit a week. Ovens take 45 minutes on their own.\n";

        return ContentItem::factory()->draft()->create([
            'title' => 'How to clean windows',
            'body_markdown' => $body,
            'body_html' => '<h2>Where a weekly clean is the wrong call</h2>',
            'summary' => 'A sentence.',
            'entities' => ['Lisbon'],
            'entity_coverage' => ['Lisbon' => true],
            'factcheck' => ['passed' => true, 'findings' => [], 'required' => false],
            ...$attributes,
        ]);
    }
}
