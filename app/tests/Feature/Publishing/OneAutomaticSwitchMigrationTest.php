<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Folding the website's switch into the project's must not start publishing
 * anything that was not publishing before.
 */
final class OneAutomaticSwitchMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Back to the day before, so the data below is written the old way.
        $this->migration()->down();
    }

    #[Test]
    public function an_automatic_project_whose_websites_were_all_switched_off_becomes_review_first(): void
    {
        $project = $this->project(['autopublish' => true]);
        $channel = $this->channel($project, ['autopublish' => false]);
        $engine = $this->schedule($project, $channel, ['mode' => 'automatic', 'origin' => 'engine']);
        $waiting = $this->schedule($project, $channel, ['mode' => 'automatic', 'origin' => 'manager', 'status' => 'blocked',
            'blocked_reason' => 'Enable automatic publishing for this website or choose review first.']);
        $sent = $this->schedule($project, $channel, ['mode' => 'automatic', 'origin' => 'engine', 'status' => 'completed']);

        $this->migration()->up();

        $this->assertFalse($project->refresh()->autopublish);
        $this->assertSchedule($engine, mode: 'review_first', status: 'active', held: false, version: 2);
        $this->assertSchedule($waiting, mode: 'review_first', status: 'active', held: false, version: 2);
        $this->assertNull($waiting->refresh()->blocked_reason);
        $this->assertSchedule($sent, mode: 'automatic', status: 'completed', held: false, version: 1);
    }

    #[Test]
    public function a_project_that_was_publishing_automatically_stays_automatic_and_keeps_what_waited_waiting(): void
    {
        $project = $this->project(['autopublish' => true]);
        $on = $this->channel($project, ['autopublish' => true, 'name' => 'Blog']);
        $off = $this->channel($project, ['autopublish' => false, 'name' => 'Shop']);
        $going = $this->schedule($project, $on, ['mode' => 'automatic', 'origin' => 'engine']);
        $aimedAtOff = $this->schedule($project, $off, ['mode' => 'automatic', 'origin' => 'manager', 'status' => 'blocked',
            'blocked_reason' => 'Enable automatic publishing for this website or choose review first.']);
        $chosenReview = $this->schedule($project, $on, ['mode' => 'review_first', 'origin' => 'manager']);

        $this->migration()->up();

        $this->assertTrue($project->refresh()->autopublish);
        $this->assertSchedule($going, mode: 'automatic', status: 'active', held: false, version: 1);
        $this->assertSchedule($aimedAtOff, mode: 'review_first', status: 'active', held: true, version: 2);
        $this->assertSchedule($chosenReview, mode: 'review_first', status: 'active', held: true, version: 1);
    }

    #[Test]
    public function a_project_with_no_tested_website_yet_keeps_its_answer(): void
    {
        $project = $this->project(['autopublish' => true]);
        $this->channel($project, ['autopublish' => false, 'verified_at' => null]);
        $nowhere = $this->schedule($project, null, ['mode' => 'automatic', 'origin' => 'engine', 'status' => 'blocked',
            'blocked_reason' => 'Choose a verified website with automatic publishing enabled.']);

        $this->migration()->up();

        $this->assertTrue($project->refresh()->autopublish);
        $this->assertSchedule($nowhere, mode: 'automatic', status: 'blocked', held: false, version: 1);
        $this->assertSame('Connect and test your website so this article can publish.', $nowhere->refresh()->blocked_reason);
    }

    #[Test]
    public function a_ymyl_project_becomes_review_first_whatever_its_website_said(): void
    {
        $project = $this->project(['autopublish' => true, 'is_ymyl' => true]);
        $this->channel($project, ['autopublish' => true]);

        $this->migration()->up();

        $this->assertFalse($project->refresh()->autopublish);
    }

    #[Test]
    public function a_review_first_project_takes_back_its_articles_and_releases_the_ones_its_switch_blocked(): void
    {
        $project = $this->project(['autopublish' => false]);
        $channel = $this->channel($project, ['autopublish' => true]);
        $explicit = $this->schedule($project, $channel, ['mode' => 'automatic', 'origin' => 'manager']);
        $stuck = $this->schedule($project, $channel, ['mode' => 'automatic', 'origin' => 'engine', 'status' => 'blocked',
            'blocked_reason' => 'Automatic publishing is turned off for this project.']);
        $reviewing = $this->schedule($project, $channel, ['mode' => 'review_first', 'origin' => 'manager']);

        $this->migration()->up();

        $this->assertFalse($project->refresh()->autopublish);
        $this->assertSchedule($explicit, mode: 'review_first', status: 'active', held: false, version: 2);
        $this->assertSchedule($stuck, mode: 'review_first', status: 'active', held: false, version: 2);
        // Review first was the project's default, not a choice about this one.
        $this->assertSchedule($reviewing, mode: 'review_first', status: 'active', held: false, version: 1);
    }

    #[Test]
    public function an_article_approved_ahead_of_a_schedule_that_now_reviews_first_goes_back_to_the_owner(): void
    {
        $flipped = $this->project(['autopublish' => true]);
        $off = $this->channel($flipped, ['autopublish' => false]);
        $early = $this->approved($this->schedule($flipped, $off, ['mode' => 'automatic', 'origin' => 'engine']));

        $staying = $this->project(['autopublish' => true]);
        $on = $this->channel($staying, ['autopublish' => true]);
        $going = $this->approved($this->schedule($staying, $on, ['mode' => 'automatic', 'origin' => 'engine']));

        $this->migration()->up();

        $this->assertSame('draft', $this->state($early));
        $this->assertSame(1, DB::table('article_approval_records')->where('content_item_id', $early->content_item_id)->count());
        // Who approved it was never recorded, so the fact check still applies.
        $this->assertSame('approved', $this->state($going));
        $this->assertTrue((bool) DB::table('article_schedules')->where('id', $going->id)->value('approved_by_avyo'));
    }

    #[Test]
    public function articles_waiting_for_review_keep_waiting_whoever_scheduled_them(): void
    {
        // Review-first once, automatic now: its calendar still waits.
        $project = $this->project(['autopublish' => true]);
        $channel = $this->channel($project, ['autopublish' => true]);
        $engine = $this->schedule($project, $channel, ['mode' => 'review_first', 'origin' => 'engine']);

        $this->migration()->up();

        $this->assertTrue($project->refresh()->autopublish);
        $this->assertSchedule($engine, mode: 'review_first', status: 'active', held: true, version: 1);
    }

    #[Test]
    public function a_website_its_owner_switched_off_counts_even_after_an_edit_made_it_unusable(): void
    {
        $project = $this->project(['autopublish' => true]);
        $this->channel($project, ['autopublish' => false, 'verified_at' => null, 'autopublish_declined_at' => now()->subWeek()]);

        $this->migration()->up();

        $this->assertFalse($project->refresh()->autopublish);
    }

    #[Test]
    public function a_delivery_the_old_switch_would_have_refused_is_withdrawn_unless_it_has_started(): void
    {
        $project = $this->project(['autopublish' => true]);
        $this->channel($project, ['autopublish' => true, 'name' => 'Blog']);
        $off = $this->channel($project, ['autopublish' => false, 'name' => 'Shop']);
        [$queued, $queuedDelivery] = $this->inFlight($project, $off, started: false);
        [$started, $startedDelivery] = $this->inFlight($project, $off, started: true);

        $this->migration()->up();

        $this->assertSame('dead_letter', DB::table('webhook_deliveries')->where('id', $queuedDelivery->id)->value('status'));
        $this->assertSchedule($queued, mode: 'review_first', status: 'blocked', held: true, version: 2);
        $row = DB::table('article_schedules')->where('id', $queued->id)->first();
        $this->assertNotNull($row);
        $this->assertSame([null, 'needs_approval'], [$row->delivery_id, $row->blocked_code]);
        $this->assertSame('draft', $this->state($queued));

        // Possibly at the website already: it keeps its identity, and is
        // marked as Avyo's approval so the next attempt hands it to the owner
        // rather than sending it — the verdict the old rules gave it.
        $this->assertSame('pending', DB::table('webhook_deliveries')->where('id', $startedDelivery->id)->value('status'));
        $this->assertSchedule($started, mode: 'review_first', status: 'dispatching', held: true, version: 1);
        $this->assertTrue((bool) DB::table('article_schedules')->where('id', $started->id)->value('approved_by_avyo'));
    }

    #[Test]
    public function a_delivery_the_old_rules_let_through_still_goes(): void
    {
        // The owner's own automatic schedule on a review-first project: the
        // old guard sent it, so the new one must not take it for Avyo's.
        $project = $this->project(['autopublish' => false]);
        $channel = $this->channel($project, ['autopublish' => true]);
        $schedule = $this->approved($this->schedule($project, $channel, ['mode' => 'automatic', 'origin' => 'manager',
            'status' => 'dispatching', 'publish_at' => now()->subMinute()]));
        $delivery = WebhookDelivery::factory()->pending()->create(['channel_id' => $channel->id, 'content_item_id' => $schedule->content_item_id,
            'article_schedule_id' => $schedule->id, 'article_schedule_version' => 1, 'article_attempt_started_at' => now()]);
        $schedule->forceFill(['delivery_id' => $delivery->id])->save();

        $this->migration()->up();

        $this->assertSchedule($schedule, mode: 'automatic', status: 'dispatching', held: false, version: 1);
        $this->assertFalse((bool) DB::table('article_schedules')->where('id', $schedule->id)->value('approved_by_avyo'));
        $this->assertSame('pending', DB::table('webhook_deliveries')->where('id', $delivery->id)->value('status'));
        $this->assertSame('approved', $this->state($schedule));
    }

    #[Test]
    public function a_block_whose_date_has_passed_waits_for_a_new_date_instead_of_going_out_on_deploy_day(): void
    {
        $project = $this->project(['autopublish' => true]);
        $channel = $this->channel($project, ['autopublish' => true]);
        $stale = $this->schedule($project, $channel, ['mode' => 'automatic', 'origin' => 'manager', 'status' => 'blocked',
            'publish_at' => now()->subDays(3), 'blocked_reason' => 'Enable automatic publishing for this website or choose review first.']);
        $missed = $this->schedule($project, $channel, ['mode' => 'automatic', 'origin' => 'engine', 'status' => 'blocked',
            'publish_at' => now()->subDays(3), 'blocked_reason' => 'This automatic publication date was missed. Choose a new date to keep articles spaced out.']);
        $checked = $this->schedule($project, $channel, ['mode' => 'automatic', 'origin' => 'engine', 'status' => 'blocked',
            'publish_at' => now()->subDay(), 'blocked_reason' => 'The fact check has not passed. Review the article before publishing.']);

        $this->migration()->up();

        foreach ([$stale, $missed] as $schedule) {
            $this->assertSame(['blocked', 'missed_date'], [
                DB::table('article_schedules')->where('id', $schedule->id)->value('status'),
                DB::table('article_schedules')->where('id', $schedule->id)->value('blocked_code'),
            ]);
        }
        $this->assertSame('fact_check', DB::table('article_schedules')->where('id', $checked->id)->value('blocked_code'));
    }

    #[Test]
    public function a_website_block_says_whether_the_website_is_switched_off_or_not_working(): void
    {
        $project = $this->project(['autopublish' => true]);
        $off = $this->channel($project, ['autopublish' => true, 'is_enabled' => false, 'name' => 'Off']);
        $broken = $this->channel($project, ['autopublish' => true, 'verified_at' => null, 'name' => 'Broken']);
        $reason = 'Choose a verified, enabled website connection for this schedule.';
        $paused = $this->schedule($project, $off, ['mode' => 'automatic', 'origin' => 'engine', 'status' => 'blocked', 'blocked_reason' => $reason]);
        $failing = $this->schedule($project, $broken, ['mode' => 'automatic', 'origin' => 'engine', 'status' => 'blocked', 'blocked_reason' => $reason]);

        $this->migration()->up();

        $this->assertSame('website_paused', DB::table('article_schedules')->where('id', $paused->id)->value('blocked_code'));
        $this->assertSame('website_not_working', DB::table('article_schedules')->where('id', $failing->id)->value('blocked_code'));
    }

    /** @return array{ArticleSchedule, WebhookDelivery} */
    private function inFlight(Project $project, Channel $channel, bool $started): array
    {
        $schedule = $this->approved($this->schedule($project, $channel, ['mode' => 'automatic', 'origin' => 'engine',
            'status' => 'dispatching', 'publish_at' => now()->subMinute()]));
        $delivery = WebhookDelivery::factory()->pending()->create([
            'channel_id' => $channel->id, 'content_item_id' => $schedule->content_item_id,
            'article_schedule_id' => $schedule->id, 'article_schedule_version' => 1,
            'article_attempt_started_at' => $started ? now() : null,
        ]);
        $schedule->forceFill(['delivery_id' => $delivery->id])->save();

        return [$schedule, $delivery];
    }

    private function approved(ArticleSchedule $schedule): ArticleSchedule
    {
        DB::table('content_items')->where('id', $schedule->content_item_id)->update(['state' => 'approved']);
        DB::table('article_approval_records')->insert(['id' => (string) Str::ulid(), 'project_id' => $schedule->project_id,
            'content_item_id' => $schedule->content_item_id, 'accepted_at' => now(), 'units' => 1,
            'policy' => 'first_article_approval', 'created_at' => now(), 'updated_at' => now()]);

        return $schedule;
    }

    private function state(ArticleSchedule $schedule): mixed
    {
        return DB::table('content_items')->where('id', $schedule->content_item_id)->value('state');
    }

    private function migration(): mixed
    {
        return require database_path('migrations/2026_09_30_120000_publish_automatically_is_one_project_switch.php');
    }

    private function assertSchedule(ArticleSchedule $schedule, string $mode, string $status, bool $held, int $version): void
    {
        $row = DB::table('article_schedules')->where('id', $schedule->id)->first();
        $this->assertNotNull($row);
        $this->assertSame([$mode, $status, $held, $version], [$row->mode, $row->status, (bool) $row->held_for_review, (int) $row->version]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function project(array $attributes): Project
    {
        return Project::factory()->create($attributes);
    }

    /** @param  array<string, mixed>  $attributes */
    private function channel(Project $project, array $attributes): Channel
    {
        app(CurrentProject::class)->set($project);

        return Channel::factory()->create([
            'type' => ChannelType::Webhook, 'is_enabled' => true, 'secret' => 'shared-secret',
            'config' => ['endpoint' => 'https://receiver.test/articles'], 'verified_at' => now(), ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function schedule(Project $project, ?Channel $channel, array $attributes): ArticleSchedule
    {
        app(CurrentProject::class)->set($project);

        // forceCreate: a guarded create would make Eloquent remember this
        // table's columns as they are before the migration, for every test after.
        return ArticleSchedule::query()->forceCreate([
            'content_item_id' => ContentItem::factory()->draft()->create()->id, 'channel_id' => $channel?->id,
            'publish_at' => now()->addDay(), 'local_date' => now()->addDay()->toDateString(), 'local_time' => '09:00',
            'timezone' => 'UTC', 'status' => 'active', 'version' => 1, ...$attributes,
        ]);
    }
}
