<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Ai\Assistant\MarketingTools;
use App\Content\UnitScore;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\DeliveryStatus;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Enums\SocialBand;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Jobs\RunStepJob;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\PublishToChannels;
use App\Publishing\WebhookPublisher;
use App\Social\Jobs\DraftInteractionReplyJob;
use App\Support\Engine\MonthPlanner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Mockery\Expectation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Focused product boundaries, exercised against preserved historical records.
 * The legacy control test explicitly enables archived workflows in testing;
 * production and local environments cannot enable them through the old flag.
 */
final class FeaturePresenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The one test in this file that wants the feature on.
     *
     * A name rather than an attribute or a second class: PHPUnit loads one test
     * class per file, and `setUp()` is the last hook that runs before the
     * application is built.
     */
    private const ON_STATE_TEST = 'the_switch_is_what_removes_all_of_it';

    /** Every scheduled entry the social presence owns. */
    private const SOCIAL_COMMANDS = [
        'social:listen',
        'social:plan',
        'social:draft',
        'social:kill-expired',
        'signals:reap',
        'threads:renew',
        'project:capture',
    ];

    /**
     * What stays scheduled either way.
     *
     * `publish:sweep-stranded` is the one worth naming. It sits among the social
     * entries in `routes/console.php` and it is not social: it recovers any
     * delivery a killed worker left at `pending`, and a webhook channel strands
     * exactly the same way a Threads one does.
     */
    private const GENERIC_COMMANDS = [
        'publish:approved',
        'engine:tick',
        'publish:sweep-stranded',
    ];

    /** Every path the feature owns, as a path rather than as a route name. */
    private const SOCIAL_PATHS = [
        // `/today` was here until the three landing screens became one. The two
        // parts of it that existed nowhere else — the refusal ledger and the §6
        // trend — moved onto Home, which is not a social route: it renders with
        // the presence off, so it cannot be asserted to disappear with it.
        ['GET', '/engage'],
        ['GET', '/api/threads/webhook'],
        ['POST', '/api/threads/webhook'],
        ['GET', '/integrations/threads/callback'],
    ];

    private Project $project;

    private User $operator;

    protected function setUp(): void
    {
        $this->writeSwitch($this->name() === self::ON_STATE_TEST);

        parent::setUp();

        $this->project = Project::factory()->create();
        $this->operator = User::factory()->create();
        $this->operator->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->set($this->project);

        Http::fake(['receiver.test/*' => Http::response([])]);
        config()->set('queue.default', 'sync');
    }

    protected function tearDown(): void
    {
        // Back to what tests/bootstrap.php put there. Every test class builds
        // its own application from the environment, so leaving this off would
        // switch the feature off for whatever runs next.
        $this->writeSwitch(true);

        parent::tearDown();
    }

    // --------------------------------------------------------- the scheduler

    #[Test]
    public function no_social_contour_is_scheduled(): void
    {
        $scheduled = $this->scheduledCommands();

        foreach (self::SOCIAL_COMMANDS as $command) {
            $this->assertNotContains(
                $command,
                $scheduled,
                "{$command} is still scheduled with the social presence off.",
            );
        }
    }

    #[Test]
    public function the_engine_keeps_its_own_clock(): void
    {
        $scheduled = $this->scheduledCommands();

        foreach (self::GENERIC_COMMANDS as $command) {
            $this->assertContains(
                $command,
                $scheduled,
                "{$command} is not social and must keep running.",
            );
        }
    }

    // ------------------------------------------------------------- the routes

    #[Test]
    public function the_feature_has_no_urls(): void
    {
        // Historical route names remain for generated clients, but every
        // retired endpoint must answer 404 before any work can happen.
        foreach (self::SOCIAL_PATHS as [$method, $path]) {
            $this->assertSame(
                404,
                $this->actingAs($this->operator)->call($method, $path)->getStatusCode(),
                "{$method} {$path} answered something other than 404.",
            );
        }

        $this->assertSame(
            404,
            $this->actingAs($this->operator)
                ->get("/projects/{$this->project->getKey()}/threads/connect")
                ->getStatusCode(),
        );

        foreach (['engage.index', 'threads.connect', 'threads.disconnect',
            'threads.callback', 'threads.webhook.verify', 'threads.webhook.receive'] as $name) {
            $this->assertTrue(Route::has($name), "The historical route name {$name} must remain for generated clients.");
        }
    }

    // ---------------------------------------------------------- the transport

    #[Test]
    public function nothing_claims_to_be_able_to_reach_threads(): void
    {
        $registry = app(ChannelPublisherRegistry::class);

        $this->assertFalse($registry->publishes(ChannelType::Threads));
        $this->assertNotContains(ChannelType::Threads, $registry->publishableTypes());

        // Still true of the transport that has nothing to do with Threads.
        $this->assertTrue($registry->publishes(ChannelType::Webhook));
    }

    #[Test]
    public function a_project_that_already_has_a_threads_channel_still_publishes(): void
    {
        // The row survives the switch being turned off — it was created when
        // the feature was on and nothing deletes it.
        $threads = Channel::factory()->create([
            'name' => 'Brand account',
            'type' => ChannelType::Threads,
            'is_enabled' => true,
            'verified_at' => now(),
        ]);

        $webhook = Channel::factory()->create([
            'name' => 'Blog',
            'type' => ChannelType::Webhook,
            'config' => ['endpoint' => 'https://receiver.test/blog'],
            'secret' => 'shared-secret',
            'is_enabled' => true,
            'verified_at' => now(),
        ]);

        $publishing = app(PublishToChannels::class);
        $article = ContentItem::factory()->published()->create([
            'title' => 'How to clean windows',
            'slug' => 'how-to-clean-windows',
            'body_html' => '<h2>Why</h2>',
        ]);

        // The article goes out. Publication as a whole is not broken by the
        // presence of a channel nothing can deliver to — the Threads row is
        // "a destination that was never selected", which is what
        // PublishToChannels::enabled() already says about a type with no
        // transport.
        $deliveries = $publishing->publishManually($article);

        $this->assertCount(1, $deliveries);
        $this->assertSame((string) $webhook->getKey(), (string) $deliveries[0]->channel_id);

        // And the post that would have gone to Threads reaches nothing and
        // throws nothing.
        $post = ContentItem::factory()->published()->create([
            'type' => ContentItemType::SocialPost,
            'parent_id' => null,
            'social_band' => SocialBand::Question,
            'channel_type' => ChannelType::Threads->value,
            'title' => 'How often do you actually clean your windows?',
            'body_markdown' => 'How often do you actually clean your windows?',
            'public_url' => null,
        ]);

        $this->assertSame([], $publishing->publishManually($post));

        // The honest half. A skip is only acceptable because the count the unit
        // card prints reads through the same filter, so the button offers zero
        // channels rather than promising one and dropping it.
        $this->assertSame(0, $publishing->manualTargets($post));

        $this->assertTrue($threads->exists);
    }

    // ------------------------------------------------------- the channel type

    #[Test]
    public function threads_is_not_a_channel_an_operator_can_add(): void
    {
        $this->assertNotContains(ChannelType::Threads, ChannelType::offered());

        $this->actingAs($this->operator)
            ->get('/channels')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('channels/index')
                ->where('types', $this->withoutThreads(...))
            );

        $this->actingAs($this->operator)
            ->post('/channels', ['name' => 'Brand account', 'type' => ChannelType::Threads->value])
            ->assertSessionHasErrors('type');
    }

    #[Test]
    public function an_existing_threads_channel_does_not_break_the_screen_it_is_on(): void
    {
        Channel::factory()->create([
            'name' => 'Brand account',
            'type' => ChannelType::Threads,
            'is_enabled' => true,
        ]);

        $this->actingAs($this->operator)
            ->get('/channels')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('channels/index')
                ->where('channels.0.type', ChannelType::Threads->value)
                ->where('channels.0.type_label', 'Threads')
            );
    }

    // ---------------------------------------------------------- the commands

    #[Test]
    public function a_gated_command_run_by_hand_says_why_and_exits_zero(): void
    {
        foreach (self::SOCIAL_COMMANDS as $name) {
            /** @var PendingCommand $command */
            $command = $this->artisan($name);

            // Exit zero, because a deliberate configuration is not a failure —
            // these end up in cron wrappers that alert on the exit code, which
            // is the noise the switch was added to remove. And name the
            // variable, because that is the only thing worth telling somebody
            // who just typed the command.
            $command->expectsOutputToContain('SOCIAL_PRESENCE_ENABLED')
                ->assertExitCode(0)
                ->run();
        }
    }

    // --------------------------------------------------------- the interface

    #[Test]
    public function the_interface_does_not_advertise_the_feature(): void
    {
        // The sidebar is rendered on every page from a shared prop, so this is
        // the whole of the Today and Conversations entries being gone.
        $this->actingAs($this->operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('social.enabled', false));

        // And the settings screen has no Threads panel to render. Absent rather
        // than in its own `unavailable` state: that state means "this
        // installation wants Threads and has no app yet" and tells the operator
        // which two variables to fill in, which is advice a deployment that
        // switched the feature off did not ask for.
        $this->actingAs($this->operator)
            ->get("/projects/{$this->project->getKey()}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/edit')
                ->missing('threads')
                ->where('social.enabled', false)
            );
    }

    // ------------------------------------------------------------- the gate

    /**
     * The same questions, asked of an application built with the switch on.
     *
     * Without this every test above could be green because something else
     * broke. It deliberately checks the two surfaces that would fail silently —
     * the schedule and the routes — rather than all six, because those are the
     * ones whose absence has no other symptom.
     */
    #[Test]
    public function the_switch_is_what_removes_all_of_it(): void
    {
        $this->assertTrue(config('social.enabled'));

        $scheduled = $this->scheduledCommands();

        foreach ([...self::SOCIAL_COMMANDS, ...self::GENERIC_COMMANDS] as $command) {
            $this->assertContains($command, $scheduled, "{$command} is not scheduled with the switch on.");
        }

        foreach (self::SOCIAL_PATHS as [$method, $path]) {
            $this->assertNotSame(
                404,
                $this->actingAs($this->operator)->call($method, $path)->getStatusCode(),
                "{$method} {$path} is missing with the switch on.",
            );
        }

        $this->assertTrue(Route::has('threads.connect'));
        $this->assertContains(ChannelType::Threads, ChannelType::offered());
        $this->assertTrue(app(ChannelPublisherRegistry::class)->publishes(ChannelType::Threads));

        $this->actingAs($this->operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('social.enabled', true));
    }

    #[Test]
    public function archived_social_and_studio_routes_refuse_direct_access_and_writes(): void
    {
        $post = ContentItem::factory()->create(['type' => ContentItemType::SocialPost, 'state' => ContentItemState::Draft]);

        foreach ([
            ['GET', '/social'], ['POST', '/social/goal'], ['GET', '/social/create'],
            ['GET', '/social/plan'], ['GET', '/studio'], ['POST', '/studio/propose'],
            ['POST', '/studio/ideas'], ['GET', "/social/posts/{$post->id}"],
            ['PATCH', "/social/posts/{$post->id}"], ['POST', "/social/posts/{$post->id}/edit"],
            ['POST', "/content/{$post->id}/approve"], ['POST', "/content/{$post->id}/publish"],
        ] as [$method, $url]) {
            $this->actingAs($this->operator)->call($method, $url)->assertNotFound();
        }

        $this->assertSame(ContentItemState::Draft, $post->refresh()->state);
        $this->assertDatabaseCount('pipeline_runs', 0);
    }

    #[Test]
    public function no_social_channel_or_assistant_action_is_offered(): void
    {
        foreach (ChannelType::offered() as $type) {
            $this->assertFalse($type->isSocial());
        }

        $tools = collect(app(MarketingTools::class)->all());
        $this->assertNotContains('write_post', $tools->map(fn ($tool) => $tool->getName())->all());
        $this->assertArrayNotHasKey('social', $tools->first(fn ($tool) => $tool->getName() === 'read_content_state')->execute([]));
    }

    #[Test]
    public function retired_pipeline_jobs_cancel_without_spending_or_discarding_history(): void
    {
        Queue::fake();
        $runner = app(PipelineRunner::class);

        foreach (['repurpose', 'content_studio', 'social_draft', 'social_listen', 'social_plan', 'social_engage'] as $pipeline) {
            $run = PipelineRun::factory()->running()->create(['pipeline' => $pipeline, 'cost_micros' => 123]);
            $step = PipelineStep::factory()->pending()->create(['pipeline_run_id' => $run->id, 'step_key' => 'not_executed']);
            (new RunStepJob($run->id, $step->step_key))->handle($runner);
            $runner->resume($run);
            $runner->dispatchReady($run);

            $this->assertSame(PipelineRunStatus::Cancelled, $run->refresh()->status);
            $this->assertSame(123, $run->cost_micros);
            $this->assertSame(PipelineStepStatus::Pending, $step->refresh()->status);
            $this->assertSame(0, $step->attempt);

            try {
                $runner->start($pipeline, $this->project);
                $this->fail("{$pipeline} started while retired.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('pipeline', $exception->errors());
            }
        }

        Queue::assertNothingPushed();
    }

    #[Test]
    public function approval_waits_for_an_explicit_publish_even_on_an_automatic_channel(): void
    {
        Queue::fake();
        /** @var Expectation $expectation */
        $expectation = $this->mock(UnitScore::class)->shouldReceive('for');
        $expectation->once()->andReturn([
            'score' => 100, 'publishable' => true, 'blocking' => [], 'checks' => [],
        ]);
        Channel::factory()->create([
            'type' => ChannelType::Webhook, 'autopublish' => true, 'verified_at' => now(),
            'is_enabled' => true, 'config' => ['endpoint' => 'https://receiver.test/blog'],
        ]);
        $article = ContentItem::factory()->draft()->create();

        $this->actingAs($this->operator)->post("/content/{$article->id}/approve")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ContentItemState::Approved, $article->refresh()->state);
        $this->assertDatabaseCount('webhook_deliveries', 0);
        Queue::assertNothingPushed();

        /** @var PendingCommand $command */
        $command = $this->artisan('publish:approved', ['project' => $this->project->slug]);
        $command->assertSuccessful()->run();
        $this->assertDatabaseCount('webhook_deliveries', 0);

        $this->actingAs($this->operator)->post("/content/{$article->id}/publish")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('webhook_deliveries', 1);
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        Http::assertNothingSent();
    }

    #[Test]
    public function historical_social_channels_do_not_consume_the_website_connection_allowance(): void
    {
        config()->set('billing.plans.1.medium.limits.channels', 1);
        $social = Channel::factory()->create(['type' => ChannelType::Threads]);
        $before = $social->refresh()->getAttributes();
        $website = ['name' => 'Website', 'type' => 'webhook', 'config' => ['endpoint' => 'https://receiver.test/blog'], 'secret' => 'test-secret'];

        $this->actingAs($this->operator)->post('/channels', $website)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->operator)->post('/channels', [...$website, 'name' => 'Second website'])->assertSessionHasErrors('name');
        $this->actingAs($this->operator)->patch("/channels/{$social->id}", $website)->assertNotFound();
        $this->actingAs($this->operator)->post("/channels/{$social->id}/ping")->assertNotFound();
        $this->actingAs($this->operator)->patch("/channels/{$social->id}/autopublish")->assertNotFound();

        $this->assertSame($before, $social->refresh()->getAttributes());
        $this->assertDatabaseCount('channels', 2);
    }

    #[Test]
    public function the_article_calendar_can_research_topics_while_social_stays_retired(): void
    {
        Queue::fake();
        $run = app(MonthPlanner::class)->start($this->project);
        $this->assertSame('research', $run->pipeline);
        $this->assertSame(1, PipelineRun::query()->count());
        $this->assertDatabaseCount('content_plans', 0);
    }

    #[Test]
    public function a_generic_pipeline_cannot_be_used_to_continue_a_social_item(): void
    {
        Queue::fake();
        $post = ContentItem::factory()->create(['type' => ContentItemType::SocialPost]);
        $runner = app(PipelineRunner::class);

        foreach ([true, false] as $inColumn) {
            $id = $inColumn ? $post->id : null;
            $input = $inColumn ? [] : ['content_item_id' => $post->id];
            $run = PipelineRun::factory()->pending()->create(['pipeline' => 'generation', 'content_item_id' => $id, 'input' => $input]);

            (new RunStepJob($run->id, 'compile_brief'))->handle($runner);
            $this->assertSame(PipelineRunStatus::Cancelled, $run->refresh()->status);

            try {
                $runner->start('generation', $this->project, $input, $id);
                $this->fail('A generic pipeline started on a retired social item.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('pipeline', $exception->errors());
            }
        }

        Queue::assertNothingPushed();
    }

    #[Test]
    public function queued_reply_drafts_stop_without_modifying_the_conversation(): void
    {
        Queue::fake();
        $interaction = Interaction::factory()->create();
        $before = $interaction->refresh()->getAttributes();

        (new DraftInteractionReplyJob($interaction->id))->handle(app(CurrentProject::class), app(PipelineRunner::class));

        $this->assertSame($before, $interaction->refresh()->getAttributes());
        $this->assertDatabaseCount('pipeline_runs', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function old_social_deliveries_and_retries_stop_without_network_or_replay(): void
    {
        Queue::fake();
        $post = ContentItem::factory()->published()->create(['type' => ContentItemType::SocialPost]);

        foreach ([ChannelType::Threads, ChannelType::Webhook] as $type) {
            $channel = Channel::factory()->create(['type' => $type]);
            $delivery = WebhookDelivery::factory()->failed()->create([
                'channel_id' => $channel->id,
                'content_item_id' => $post->id,
                'payload_snapshot' => ['content' => ['type' => 'social_post']],
            ]);
            $attempts = $delivery->attempts;

            (new DeliverWebhookJob($delivery->id))->handle(app(ChannelPublisherRegistry::class), app(CurrentProject::class));

            $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
            $this->assertNull($delivery->next_attempt_at);
            $this->assertSame($attempts, $delivery->attempts);
            $this->actingAs($this->operator)->post("/deliveries/{$delivery->id}/replay")->assertStatus(409);
        }

        $this->assertDatabaseCount('webhook_deliveries', 2);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_article_tick_drafts_due_work_without_reviving_social_or_approving_historical_drafts(): void
    {
        Queue::fake();
        $this->project->update(['autopublish' => true, 'weekly_target' => 7]);
        $plan = $this->project->contentPlans()->create(['month' => now()->startOfMonth()]);
        $idea = ContentItem::factory()->create([
            'state' => ContentItemState::Idea,
            'content_plan_id' => $plan->id,
            'scheduled_for' => now(),
        ]);
        $draft = ContentItem::factory()->create(['state' => ContentItemState::Draft]);
        ContentItem::factory()->published()->create(['planned_derivatives' => ['linkedin']]);
        PipelineRun::factory()->pending()->create(['pipeline' => 'repurpose']);
        $post = ContentItem::factory()->create(['type' => ContentItemType::SocialPost]);
        PipelineRun::factory()->pending()->create(['pipeline' => 'generation', 'content_item_id' => $post->id]);
        PipelineRun::factory()->pending()->create(['pipeline' => 'generation', 'input' => ['content_item_id' => $post->id]]);

        /** @var PendingCommand $command */
        $command = $this->artisan('engine:tick', ['--project' => $this->project->slug]);
        $command->assertSuccessful()->run();

        $this->assertSame(ContentItemState::Idea, $idea->refresh()->state);
        $this->assertSame(ContentItemState::Draft, $draft->refresh()->state);
        $this->assertSame(['generation', 'generation', 'generation', 'repurpose'], PipelineRun::query()->orderBy('pipeline')->pluck('pipeline')->all());
        $this->assertSame(1, PipelineRun::query()->where('pipeline', 'generation')->where('content_item_id', $idea->id)->count());
    }

    #[Test]
    public function a_direct_webhook_attempt_cannot_send_an_archived_social_snapshot(): void
    {
        $delivery = WebhookDelivery::factory()->pending()->create([
            'payload_snapshot' => ['content' => ['type' => 'social_post']],
        ]);

        app(WebhookPublisher::class)->attempt($delivery);

        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- machinery

    /**
     * Put the switch in the environment, before anything reads it.
     *
     * All three superglobals, for the reason `tests/bootstrap.php` gives at
     * length: Dotenv's adapters consult `$_SERVER` first and Docker's
     * `environment:` block lands there, so writing only one of them is a change
     * that quietly does not happen.
     */
    private function writeSwitch(bool $enabled): void
    {
        $value = $enabled ? 'true' : 'false';

        $_ENV['SOCIAL_PRESENCE_ENABLED'] = $value;
        $_SERVER['SOCIAL_PRESENCE_ENABLED'] = $value;
        putenv("SOCIAL_PRESENCE_ENABLED={$value}");
    }

    /**
     * The connect dialog's options, with no Threads among them.
     *
     * @param  Collection<int, mixed>  $types
     */
    private function withoutThreads(Collection $types): bool
    {
        return ! $types->pluck('value')->contains(ChannelType::Threads->value);
    }

    /**
     * Every artisan command the scheduler would run.
     *
     * `routes/console.php` is required when the console kernel bootstraps,
     * which opening a page does not do — so the schedule reads as empty unless
     * this asks for it explicitly.
     *
     * The event's `command` is a whole shell line ("'php' 'artisan' engine:tick"
     * and, for a background entry, the redirection around it), so the name is
     * matched inside it rather than compared to it.
     *
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        app(ConsoleKernel::class)->bootstrap();

        $lines = array_map(
            static fn (Event $event): string => (string) $event->command,
            app(Schedule::class)->events(),
        );

        $found = [];

        foreach ([...self::SOCIAL_COMMANDS, ...self::GENERIC_COMMANDS] as $name) {
            foreach ($lines as $line) {
                if (str_contains($line, $name)) {
                    $found[] = $name;

                    break;
                }
            }
        }

        return $found;
    }
}
