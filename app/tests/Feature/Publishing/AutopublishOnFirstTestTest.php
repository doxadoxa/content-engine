<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\WebhookPublisher;
use App\Publishing\WordPressPublisher;
use App\Support\Content\ManagerContent;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A project that already chose automatic publishing should not be told its
 * publishing needs setting up once the one website it connected has passed
 * its test — but nothing here may publish where the owner has not said so.
 */
final class AutopublishOnFirstTestTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'website_url' => 'https://website.test',
            'autopublish' => true,
            'onboarding' => ['article_automation_started_at' => now()->toIso8601String()],
        ]);
        app(CurrentProject::class)->set($this->project);
        Queue::fake();
    }

    #[Test]
    public function the_first_passing_test_turns_on_automatic_publishing_for_an_automatic_project(): void
    {
        $channel = $this->webhook();

        $this->assertFalse($this->workflow()['ready']);

        $this->ping($channel);

        $this->assertTrue($channel->refresh()->autopublish);
        $this->assertTrue($this->workflow()['ready']);
    }

    #[Test]
    public function a_review_first_project_keeps_the_channel_waiting_for_approval(): void
    {
        $this->project->forceFill(['autopublish' => false])->save();
        $channel = $this->webhook();

        $this->ping($channel);

        $this->assertNotNull($channel->refresh()->verified_at);
        $this->assertFalse($channel->autopublish);
    }

    #[Test]
    public function a_second_eligible_website_is_left_for_the_owner_to_choose(): void
    {
        $first = $this->webhook(['verified_at' => now(), 'autopublish' => false]);
        $second = $this->webhook();

        $this->ping($second);

        $this->assertNotNull($second->refresh()->verified_at);
        $this->assertFalse($second->autopublish);
        $this->assertFalse($first->refresh()->autopublish);
    }

    #[Test]
    public function a_retest_does_not_overrule_an_owner_who_switched_it_off(): void
    {
        $channel = $this->webhook(['verified_at' => now()->subDay(), 'autopublish' => false]);

        $this->ping($channel);

        $this->assertFalse($channel->refresh()->autopublish);
    }

    #[Test]
    public function a_failed_test_changes_nothing(): void
    {
        $channel = $this->webhook();

        $delivery = $this->ping($channel, Http::response([], 401));

        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        $this->assertNull($channel->refresh()->verified_at);
        $this->assertFalse($channel->autopublish);
    }

    #[Test]
    public function a_disabled_connection_is_not_switched_to_automatic(): void
    {
        $channel = $this->webhook(['is_enabled' => false]);

        $this->ping($channel);

        $this->assertFalse($channel->refresh()->autopublish);
    }

    #[Test]
    public function wordpress_turns_automatic_once_its_article_test_passes_even_after_a_page_check(): void
    {
        // Verified earlier by binding a tracked page, which does not prove
        // it can take articles; the article test is the one that does.
        $channel = Channel::factory()->create([
            'type' => ChannelType::WordPress,
            'config' => ['page_receiver_base' => 'https://website.test/wp-json/avyo/v1', 'username' => 'publisher'],
            'secret' => 'application-password',
            'verified_at' => now()->subDay(),
        ]);

        Http::fake(fn (Request $request) => Http::response([
            'contract' => 1,
            'delivery_id' => $request['delivery_id'],
            'capabilities' => ['article_publish' => true],
        ]));

        $publisher = app(WordPressPublisher::class);
        $publisher->attempt($publisher->ping($channel, $this->project));

        $channel->refresh();
        $this->assertTrue($channel->config['article_publishing_verified']);
        $this->assertTrue($channel->autopublish);
    }

    #[Test]
    public function an_owners_no_survives_a_connection_change_and_a_retest(): void
    {
        $owner = $this->owner();
        $channel = $this->webhook(['verified_at' => now(), 'autopublish' => true]);

        $this->actingAs($owner)->patch(route('channels.autopublish', $channel))->assertRedirect();
        $this->assertNotNull($channel->refresh()->autopublish_declined_at);

        // Rotating the secret resets the flag and the verification together,
        // which is exactly what used to erase the owner's answer.
        $this->actingAs($owner)->patch(route('channels.update', $channel), [
            'name' => $channel->name,
            'type' => $channel->type->value,
            'config' => $channel->config,
            'secret' => 'rotated-secret',
            'is_enabled' => true,
            'autopublish' => false,
        ])->assertRedirect();
        $this->assertNull($channel->refresh()->verified_at);

        $this->ping($channel);

        $this->assertNotNull($channel->refresh()->verified_at);
        $this->assertFalse($channel->autopublish);
    }

    #[Test]
    public function the_channel_form_records_the_owners_answer_either_way(): void
    {
        $owner = $this->owner();
        $channel = $this->webhook(['verified_at' => now(), 'autopublish' => true]);
        $form = fn (bool $automatic): array => [
            'name' => $channel->name,
            'type' => $channel->type->value,
            'config' => $channel->config,
            'is_enabled' => true,
            'autopublish' => $automatic,
        ];

        $this->actingAs($owner)->patch(route('channels.update', $channel), $form(false))->assertRedirect();
        $this->assertNotNull($channel->refresh()->autopublish_declined_at);

        $this->actingAs($owner)->patch(route('channels.update', $channel), $form(true))->assertRedirect();
        $this->assertNull($channel->refresh()->autopublish_declined_at);
        $this->assertTrue($channel->autopublish);
    }

    #[Test]
    public function a_pass_for_a_connection_edited_mid_test_does_not_count(): void
    {
        $channel = $this->webhook();
        $asSent = $channel->fresh();

        // The owner points it somewhere else while the ping is in flight; the
        // ping to the old address then passes and stamps the row as verified.
        $channel->forceFill(['config' => ['endpoint' => 'https://elsewhere.test/hook'], 'verified_at' => now()])->save();

        app(ArticleSchedules::class)->adoptProjectAutopublish($asSent);

        $this->assertFalse($channel->refresh()->autopublish);
    }

    #[Test]
    public function an_article_scheduled_before_the_switch_stops_waiting_once_it_is_ticked(): void
    {
        $owner = $this->owner();
        $channel = $this->webhook(['verified_at' => now(), 'autopublish' => false]);
        $schedule = $this->scheduleOn($channel, $owner);

        $this->assertSame('blocked', $schedule->status);
        $this->assertSame(ArticleSchedules::AWAITING_AUTOPUBLISH, $schedule->blocked_reason);

        $this->actingAs($owner)->patch(route('channels.update', $channel), [
            'name' => $channel->name,
            'type' => $channel->type->value,
            'config' => $channel->config,
            'is_enabled' => true,
            'autopublish' => true,
        ])->assertRedirect();

        $schedule->refresh();
        $this->assertSame('active', $schedule->status);
        $this->assertNull($schedule->blocked_reason);
        // A form open on the old version must not overwrite the new state.
        $this->assertSame(2, $schedule->version);
    }

    #[Test]
    public function switching_it_off_says_so_on_the_articles_that_were_waiting_for_it(): void
    {
        $owner = $this->owner();
        $channel = $this->webhook(['verified_at' => now(), 'autopublish' => true]);
        $automatic = $this->scheduleOn($channel, $owner);
        $reviewed = $this->scheduleOn($channel, $owner, 'review_first');

        $this->actingAs($owner)->patch(route('channels.autopublish', $channel))->assertRedirect();

        $this->assertSame('blocked', $automatic->refresh()->status);
        $this->assertSame(ArticleSchedules::AWAITING_AUTOPUBLISH, $automatic->blocked_reason);
        $this->assertSame('active', $reviewed->refresh()->status);
    }

    #[Test]
    public function a_block_the_switch_did_not_cause_is_left_standing(): void
    {
        $owner = $this->owner();
        $channel = $this->webhook(['verified_at' => now(), 'autopublish' => false]);
        $schedule = $this->scheduleOn($channel, $owner);
        $schedule->forceFill(['blocked_reason' => 'Publishing is paused for this project or its plan.'])->save();

        $this->actingAs($owner)->patch(route('channels.autopublish', $channel))->assertRedirect();

        $this->assertSame('blocked', $schedule->refresh()->status);
    }

    #[Test]
    public function the_passing_test_that_switches_it_on_releases_the_waiting_articles_too(): void
    {
        $owner = $this->owner();
        $channel = $this->webhook();
        // Scheduled against a channel verified and then reset by an edit, so
        // it is waiting when the new test passes.
        $channel->forceFill(['verified_at' => now()])->save();
        $schedule = $this->scheduleOn($channel, $owner);
        $channel->forceFill(['verified_at' => null])->save();

        $this->ping($channel);

        $this->assertTrue($channel->refresh()->autopublish);
        $this->assertSame('active', $schedule->refresh()->status);
    }

    #[Test]
    public function channels_already_switched_off_keep_their_no_through_the_migration(): void
    {
        $off = $this->webhook(['verified_at' => now(), 'autopublish' => false]);
        $on = $this->webhook(['verified_at' => now(), 'autopublish' => true]);

        $migration = require database_path('migrations/2026_09_30_100000_add_autopublish_declined_at_to_channels.php');
        $migration->down();
        $migration->up();

        $this->assertNotNull($off->refresh()->autopublish_declined_at);
        $this->assertNull($on->refresh()->autopublish_declined_at);

        // An edit resets the connection; the re-test must not overrule an
        // opt-out that predates the column.
        $off->forceFill(['verified_at' => null])->save();
        $on->delete();
        $this->ping($off);

        $this->assertFalse($off->refresh()->autopublish);
    }

    #[Test]
    public function the_switch_is_decided_under_a_lock_held_to_the_write(): void
    {
        $owner = $this->owner();
        $channel = $this->webhook(['verified_at' => now(), 'autopublish' => true]);

        // A `for update` read outside a transaction of its own is released
        // as soon as it returns; the suite's own transaction would hide that.
        $baseline = DB::transactionLevel();
        $levels = [];
        DB::listen(function (QueryExecuted $query) use (&$levels): void {
            if (str_contains($query->sql, 'for update') && str_contains($query->sql, '"channels"')) {
                $levels[] = DB::transactionLevel();
            }
        });

        $this->actingAs($owner)->patch(route('channels.autopublish', $channel))->assertRedirect();
        $this->actingAs($owner)->patch(route('channels.update', $channel), [
            'name' => $channel->name,
            'type' => $channel->type->value,
            'config' => ['endpoint' => 'https://website.test/blog/webhook-2'],
            'is_enabled' => true,
            'autopublish' => false,
        ])->assertRedirect();
        $channel->forceFill(['autopublish_declined_at' => null])->save();
        $this->ping($channel);

        $this->assertCount(3, $levels);
        foreach ($levels as $level) {
            $this->assertGreaterThan($baseline, $level);
        }
    }

    private function scheduleOn(Channel $channel, User $owner, string $mode = 'automatic'): ArticleSchedule
    {
        return app(ArticleSchedules::class)->save($owner, ContentItem::factory()->create(), [
            'expected_version' => null,
            'local_date' => now($this->project->timezone)->addDays(2)->toDateString(),
            'local_time' => '10:00',
            'mode' => $mode,
            'channel_id' => $channel->id,
        ]);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->projects()->attach($this->project, ['role' => 'owner']);

        return $owner;
    }

    /** @param  array<string, mixed>  $attributes */
    private function webhook(array $attributes = []): Channel
    {
        return Channel::factory()->create([
            'type' => ChannelType::Webhook,
            'config' => ['endpoint' => 'https://website.test/blog/webhook'],
            'secret' => 'shared-secret',
            ...$attributes,
        ]);
    }

    private function ping(Channel $channel, mixed $response = null): WebhookDelivery
    {
        Http::fake(['website.test/*' => $response ?? Http::response(['status' => 'ok'])]);

        $publisher = app(WebhookPublisher::class);

        return $publisher->attempt($publisher->ping($channel, $this->project));
    }

    /** @return array<string, mixed> */
    private function workflow(): array
    {
        return ManagerContent::workflow($this->project->fresh());
    }
}
