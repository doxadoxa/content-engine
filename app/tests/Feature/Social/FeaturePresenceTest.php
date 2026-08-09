<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Enums\ChannelType;
use App\Enums\ContentItemType;
use App\Enums\SocialBand;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\PublishToChannels;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `SOCIAL_PRESENCE_ENABLED=false`: the feature is absent, not idle.
 *
 * An operator with no Meta app has nothing for phases 12.1–12.6 to do, and
 * "nothing to do" was previously expressed by running everything and finding
 * out. Five contours woke up hourly to skip every project; two sidebar entries
 * led to screens with nothing on them; Meta could have subscribed to a webhook
 * this installation would never have an app for. One environment variable,
 * read once in `config/social.php`, removes all of it.
 *
 * # Why the switch is set before the application exists
 *
 * It is read while configuration and routes are being built — `config/social.php`
 * calls `env()`, `routes/console.php` and `routes/web.php` ask the config
 * whether to register anything — and none of that can be re-decided afterwards.
 * `config()->set()` in a test body would change the value and change nothing
 * else, which is the worst possible outcome: assertions about routes and the
 * schedule would go on passing for the wrong reason. So the environment is
 * written in {@see setUp()} and the application is built from it, once per
 * test.
 *
 * # Why one test here runs with the feature on
 *
 * Every assertion below is an absence, and an absence proves nothing on its
 * own: a typo in a route name, a schedule that failed to load, a test that
 * silently stopped booting the console kernel would all satisfy them.
 * {@see the_switch_is_what_removes_all_of_it()} runs the same checks inverted
 * against an application built with the switch on, in the same file, so that
 * the off-state expectations cannot pass vacuously. It is the only test here
 * that gets the suite's ordinary environment (`tests/bootstrap.php` keeps the
 * feature on for the other 1097, which exercise it heavily).
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
        'engine:tick',
        'publish:approved',
        'publish:sweep-stranded',
    ];

    /** Every path the feature owns, as a path rather than as a route name. */
    private const SOCIAL_PATHS = [
        ['GET', '/today'],
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
        // Not registered, rather than registered and refusing. The webhook is
        // the reason: a 503 tells Meta there is an endpoint here having a bad
        // day and invites it to keep delivering.
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

        foreach (['today.index', 'engage.index', 'threads.connect', 'threads.disconnect',
            'threads.callback', 'threads.webhook.verify', 'threads.webhook.receive'] as $name) {
            $this->assertFalse(Route::has($name), "The route {$name} still exists.");
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
            ->get('/dashboard')
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
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('social.enabled', true));
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
