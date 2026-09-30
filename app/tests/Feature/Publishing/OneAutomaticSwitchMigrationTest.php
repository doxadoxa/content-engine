<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        return ArticleSchedule::query()->create([
            'content_item_id' => ContentItem::factory()->draft()->create()->id, 'channel_id' => $channel?->id,
            'publish_at' => now()->addDay(), 'local_date' => now()->addDay()->toDateString(), 'local_time' => '09:00',
            'timezone' => 'UTC', 'status' => 'active', 'version' => 1, ...$attributes,
        ]);
    }
}
