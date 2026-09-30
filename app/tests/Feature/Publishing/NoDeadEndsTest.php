<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Content\ArticleScore;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Publishing\Articles\ArticleApproval;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\Articles\BlockedCode;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\WebhookPublisher;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every refusal leaves the owner an action that works, and every path here
 * ends with the article published.
 */
final class NoDeadEndsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Channel $channel;

    /** What the website answers next: to tests and to articles alike. */
    private int $answer = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T08:00:00Z'));
        Queue::fake();
        $this->project = Project::factory()->create(['autopublish' => true, 'timezone' => 'UTC',
            'onboarding' => ['article_automation_started_at' => now()->subDay()->toIso8601String()]]);
        app(CurrentProject::class)->set($this->project);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        $this->channel = Channel::factory()->create(['type' => ChannelType::Webhook, 'is_enabled' => true, 'secret' => 'shared-secret',
            'config' => ['endpoint' => 'https://receiver.test/articles'], 'verified_at' => now()]);
        $this->mock(ArticleScore::class, function (MockInterface $mock): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('for');
            $expectation->andReturn(['score' => 100, 'publishable' => true, 'blocking' => [], 'checks' => []]);
        });
        Http::fake(fn (Request $request) => Http::response(['public_url' => 'https://example.test/article'], $this->answer));
    }

    #[Test]
    public function a_retry_refused_after_the_switch_to_review_first_ends_published_through_approve(): void
    {
        [$item, $delivery] = $this->sentByAvyoAndRetrying();

        $this->project->update(['autopublish' => false]);
        app(ArticleSchedules::class)->followProject($this->project);
        $this->retry($delivery);

        // Handed back: a draft again, waiting for the owner, same delivery.
        $schedule = $this->scheduleOf($item);
        $this->assertSame(ContentItemState::Draft, $item->fresh()->state);
        $this->assertSame(['blocked', BlockedCode::NEEDS_APPROVAL, $delivery->id], [$schedule->status, $schedule->blocked_code, $schedule->delivery_id]);
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->fresh()->status);
        try {
            app(WebhookPublisher::class)->replay($delivery->fresh());
            $this->fail('Try again is not the way out; approving is.');
        } catch (ValidationException $refusal) {
            $this->assertStringContainsString('Approve the article first', $refusal->getMessage());
        }
        // Nothing the scheduler does in the meantime changes that.
        app(ArticleSchedules::class)->dispatch($item);
        $this->assertSame(BlockedCode::NEEDS_APPROVAL, $this->scheduleOf($item)->blocked_code);

        $this->actingAs($this->owner)->withSession(['current_project_id' => $this->project->id])
            ->post("/content/{$item->id}/approve")->assertSessionHasNoErrors()->assertRedirect();

        // The same delivery goes again: it may have reached the website.
        $this->assertSame([DeliveryStatus::Pending, 'dispatching'], [$delivery->fresh()->status, $this->scheduleOf($item)->status]);
        $this->answer = 200;
        app(WebhookPublisher::class)->attempt($delivery->fresh());
        $this->assertPublished($item, $delivery);
    }

    #[Test]
    public function the_same_handed_back_article_ends_published_through_publish_now(): void
    {
        [$item, $delivery] = $this->sentByAvyoAndRetrying();
        $this->project->update(['autopublish' => false]);
        app(ArticleSchedules::class)->followProject($this->project);
        $this->retry($delivery);
        $this->assertTrue(app(ArticleSchedules::class)->props($item->fresh())['publish_now']['available']);

        $this->actingAs($this->owner)->withSession(['current_project_id' => $this->project->id])
            ->post("/content/{$item->id}/publish-now")->assertSessionHasNoErrors();

        $this->answer = 200;
        app(WebhookPublisher::class)->attempt($delivery->fresh());
        $this->assertPublished($item, $delivery);
    }

    #[Test]
    public function a_fact_check_that_fails_after_avyo_approved_ends_published_once_a_person_approves(): void
    {
        $item = $this->draft();
        $this->engineSchedule($item);
        $this->travel(1)->hours();
        $delivery = app(ArticleSchedules::class)->dispatch($item)[0];
        $item->forceFill(['factcheck' => ['passed' => false]])->save();

        app(WebhookPublisher::class)->attempt($delivery);

        $this->assertSame(ContentItemState::Draft, $item->fresh()->state);
        $this->assertSame(BlockedCode::FACT_CHECK, $this->scheduleOf($item)->blocked_code);
        Http::assertNothingSent();

        // A person has read it and overrules the check. Nothing was sent
        // before, so a fresh delivery goes.
        app(ArticleApproval::class)->approve($item);
        $sent = app(ArticleSchedules::class)->dispatch($item);
        $this->assertCount(1, $sent);
        $this->assertNotSame($delivery->id, $sent[0]->id);
        app(WebhookPublisher::class)->attempt($sent[0]);
        $this->assertPublished($item, $sent[0]);
    }

    #[Test]
    public function holding_an_article_a_person_approved_sends_it_back_to_draft_without_charging_twice(): void
    {
        $item = $this->draft();
        $schedule = app(ArticleSchedules::class)->save($this->owner, $item, $this->slot());
        app(ArticleApproval::class)->approve($item);
        $this->assertFalse($schedule->fresh()->approved_by_avyo);

        app(ArticleSchedules::class)->save($this->owner, $item, [...$this->slot(), 'expected_version' => 1, 'hold' => true]);
        $this->assertSame(ContentItemState::Draft, $item->fresh()->state);

        // A new date on a review-first project asks the same.
        app(ArticleApproval::class)->approve($item);
        $this->project->update(['autopublish' => false]);
        app(ArticleSchedules::class)->save($this->owner, $item, [...$this->slot(), 'expected_version' => 2, 'local_time' => '15:00']);
        $this->assertSame(ContentItemState::Draft, $item->fresh()->state);

        app(ArticleApproval::class)->approve($item);
        $this->assertSame(1, (int) DB::table('article_approval_records')->where('content_item_id', $item->id)->sum('units'));
    }

    #[Test]
    public function a_failed_website_test_holds_an_article_without_spending_an_attempt_until_a_test_passes(): void
    {
        $item = $this->draft();
        $this->engineSchedule($item);
        $this->travel(1)->hours();
        $delivery = app(ArticleSchedules::class)->dispatch($item)[0];

        $this->answer = 500;
        $this->test();
        $this->assertNull($this->channel->fresh()->verified_at);

        app(WebhookPublisher::class)->attempt($delivery->fresh());
        $held = $delivery->fresh();
        $this->assertSame([DeliveryStatus::Retrying, 0, 1], [$held->status, $held->attempts, $held->deferrals]);
        Http::assertNotSent(fn (Request $request): bool => ($request['event'] ?? null) !== 'ping');

        $this->answer = 200;
        $this->test();

        // Sent on at once, by a job that carries the time it was made due.
        $due = $delivery->fresh()->next_attempt_at;
        $this->assertNotNull($due);
        $this->assertTrue($due->lessThanOrEqualTo(now()));
        Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $job): bool => $job->deliveryId === $delivery->id && $job->scheduledFor === $due->toIso8601String());
        app(WebhookPublisher::class)->attempt($delivery->fresh(), $due);
        $this->assertPublished($item, $delivery);
    }

    #[Test]
    public function an_unusable_website_says_whether_it_is_switched_off_or_not_working(): void
    {
        $item = $this->draft();
        $this->engineSchedule($item);
        $this->travel(1)->hours();

        $this->channel->forceFill(['verified_at' => null])->save();
        app(ArticleSchedules::class)->dispatch($item);
        $this->assertSame(BlockedCode::WEBSITE_NOT_WORKING, $this->scheduleOf($item)->blocked_code);

        $this->channel->forceFill(['verified_at' => now(), 'is_enabled' => false])->save();
        app(ArticleSchedules::class)->dispatch($item);
        $this->assertSame(BlockedCode::WEBSITE_PAUSED, $this->scheduleOf($item)->blocked_code);
    }

    #[Test]
    public function removing_a_website_checks_for_articles_on_their_way_under_the_project_lock(): void
    {
        $baseline = DB::transactionLevel();
        $order = [];
        DB::listen(function (QueryExecuted $query) use (&$order): void {
            if (str_contains($query->sql, 'for update') && str_contains($query->sql, '"projects"')) {
                $order[] = ['lock', DB::transactionLevel()];
            } elseif (str_contains($query->sql, 'exists') && str_contains($query->sql, '"webhook_deliveries"')) {
                $order[] = ['check', DB::transactionLevel()];
            }
        });

        $this->actingAs($this->owner)->withSession(['current_project_id' => $this->project->id])
            ->delete(route('channels.destroy', $this->channel))->assertSessionHasNoErrors();

        $this->assertSame('lock', $order[0][0] ?? null);
        $this->assertContains('check', array_column($order, 0));
        foreach ($order as [, $level]) {
            $this->assertGreaterThan($baseline, $level);
        }
        $this->assertNull(Channel::query()->find($this->channel->id));
    }

    /** @return array{ContentItem, WebhookDelivery} */
    private function sentByAvyoAndRetrying(): array
    {
        $item = $this->draft();
        $this->engineSchedule($item);
        $this->travel(1)->hours();
        $delivery = app(ArticleSchedules::class)->dispatch($item)[0];
        $this->assertTrue($this->scheduleOf($item)->approved_by_avyo);

        $this->answer = 503;
        app(WebhookPublisher::class)->attempt($delivery);
        $this->assertSame([DeliveryStatus::Retrying, 1], [$delivery->fresh()->status, $delivery->fresh()->attempts]);

        return [$item, $delivery];
    }

    private function retry(WebhookDelivery $delivery): void
    {
        $due = $delivery->fresh()->next_attempt_at;
        $this->assertNotNull($due);
        $this->travelTo($due);
        app(WebhookPublisher::class)->attempt($delivery->fresh(), $due);
    }

    private function test(): void
    {
        $publisher = app(WebhookPublisher::class);
        $publisher->attempt($publisher->ping($this->channel, $this->project));
    }

    private function assertPublished(ContentItem $item, WebhookDelivery $delivery): void
    {
        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->assertSame(ContentItemState::Published, $item->fresh()->state);
        $this->assertSame('completed', $this->scheduleOf($item)->status);
    }

    private function draft(): ContentItem
    {
        return ContentItem::factory()->draft()->create(['body_html' => '<p>A useful article.</p>', 'factcheck' => ['passed' => true]]);
    }

    private function engineSchedule(ContentItem $item): void
    {
        $this->assertNotNull(app(ArticleSchedules::class)->scheduleNew($item, now()->addHour()));
    }

    private function scheduleOf(ContentItem $item): ArticleSchedule
    {
        return ArticleSchedule::query()->where('content_item_id', $item->id)->firstOrFail();
    }

    /** @return array{expected_version: int|null, local_date: string, local_time: string, hold: bool} */
    private function slot(): array
    {
        return ['expected_version' => null, 'local_date' => '2026-09-16', 'local_time' => '10:00', 'hold' => false];
    }
}
