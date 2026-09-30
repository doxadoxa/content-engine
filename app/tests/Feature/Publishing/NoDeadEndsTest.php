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
use App\Publishing\Articles\ArticleDeliveryGuard;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\Articles\BlockedCode;
use App\Publishing\Articles\PublicationStatus;
use App\Publishing\HeldArticles;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\Jobs\ResumeHeldArticlesJob;
use App\Publishing\WebhookPublisher;
use App\Publishing\WordPressPublisher;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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

    /** @var (callable(Request): mixed)|null A whole answer, for a website that says more than a status. */
    private $responder = null;

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
        // One fake for the whole test, answering through these properties: a
        // second Http::fake() would sit behind the first and never answer.
        Http::fake(fn (Request $request) => $this->responder !== null ? ($this->responder)($request)
            : Http::response(['public_url' => 'https://example.test/article'], $this->answer));
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
    public function newly_holding_an_article_a_person_approved_sends_it_back_to_draft_without_charging_twice(): void
    {
        $item = $this->draft();
        $schedule = app(ArticleSchedules::class)->save($this->owner, $item, $this->slot());
        app(ArticleApproval::class)->approve($item);
        $this->assertFalse($schedule->fresh()->approved_by_avyo);

        app(ArticleSchedules::class)->save($this->owner, $item, [...$this->slot(), 'expected_version' => 1, 'hold' => true]);
        $this->assertSame(ContentItemState::Draft, $item->fresh()->state);

        // Approved again under the hold: a new time keeps that approval.
        app(ArticleApproval::class)->approve($item);
        app(ArticleSchedules::class)->save($this->owner, $item, [...$this->slot(), 'expected_version' => 2, 'local_time' => '15:00']);
        $this->assertSame(ContentItemState::Approved, $item->fresh()->state);
        $this->assertSame(1, (int) DB::table('article_approval_records')->where('content_item_id', $item->id)->sum('units'));
    }

    #[Test]
    public function on_a_review_first_project_picking_a_date_after_approving_publishes_at_that_date(): void
    {
        $this->project->update(['autopublish' => false]);
        $item = $this->draft();
        // "Approved. Pick a date, or publish it now."
        app(ArticleApproval::class)->approve($item);

        $schedule = app(ArticleSchedules::class)->save($this->owner, $item, $this->slot());

        $this->assertSame(ContentItemState::Approved, $item->fresh()->state);
        $this->assertSame('review_first', $schedule->mode);
        $this->travelTo(CarbonImmutable::parse('2026-09-16T10:00:00Z'));
        $sent = app(ArticleSchedules::class)->dispatch($item);
        $this->assertCount(1, $sent);
        app(WebhookPublisher::class)->attempt($sent[0]);
        $this->assertPublished($item, $sent[0]);
    }

    #[Test]
    public function a_handed_back_article_stays_handed_back_until_a_person_approves(): void
    {
        $item = $this->draft();
        $this->engineSchedule($item);
        $this->travel(1)->hours();
        $delivery = app(ArticleSchedules::class)->dispatch($item)[0];
        $this->answer = 503;
        app(WebhookPublisher::class)->attempt($delivery);
        $item->forceFill(['factcheck' => ['passed' => false]])->save();
        $this->retry($delivery);
        $reason = $this->scheduleOf($item)->blocked_reason;

        // The scheduler looks at it every minute; it must keep saying why.
        app(ArticleSchedules::class)->dispatch($item);

        $schedule = $this->scheduleOf($item);
        $this->assertSame([BlockedCode::FACT_CHECK, $reason, $delivery->id], [$schedule->blocked_code, $schedule->blocked_reason, $schedule->delivery_id]);
    }

    #[Test]
    public function sending_back_a_handed_back_article_works_and_it_still_ends_published(): void
    {
        [$item, $delivery] = $this->sentByAvyoAndRetrying();
        $this->project->update(['autopublish' => false]);
        app(ArticleSchedules::class)->followProject($this->project);
        $this->retry($delivery);

        $this->actingAs($this->owner)->withSession(['current_project_id' => $this->project->id])
            ->post(route('content.reject', $item), ['reason' => 'off_brand', 'note' => 'Warmer, please.'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(ContentItemState::Draft, $item->fresh()->state);
        $this->assertTrue(ArticleSchedules::awaitingOwnerAfterAttempt($this->scheduleOf($item)));

        app(ArticleApproval::class)->approve($item);
        $this->assertCount(1, app(ArticleSchedules::class)->dispatch($item));
        $this->answer = 200;
        app(WebhookPublisher::class)->attempt($delivery->fresh());
        $this->assertPublished($item, $delivery);
    }

    #[Test]
    public function an_article_edited_while_handed_back_goes_as_a_fresh_update_with_its_new_words(): void
    {
        [$item, $delivery] = $this->sentByAvyoAndRetrying();
        $this->project->update(['autopublish' => false]);
        app(ArticleSchedules::class)->followProject($this->project);
        $this->retry($delivery);

        $item->forceFill(['title' => 'A better title'])->save();
        app(ArticleApproval::class)->approve($item);
        $sent = app(ArticleSchedules::class)->dispatch($item);

        // The first attempt may have arrived, so this one replaces it.
        $this->assertCount(1, $sent);
        $this->assertNotSame($delivery->id, $sent[0]->id);
        $this->assertSame('content.updated', $sent[0]->payload_snapshot['event']);
        $this->assertSame('A better title', $sent[0]->payload_snapshot['content']['title']);
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->fresh()->status);
        // Superseded: its identity is released and Try again is not offered.
        $this->assertNull($delivery->fresh()->dispatch_key);
        $this->assertFalse(PublicationStatus::canTryAgain($delivery->fresh()));
        $this->answer = 200;
        app(WebhookPublisher::class)->attempt($sent[0]);
        $this->assertPublished($item, $sent[0]);
        $this->assertFalse(PublicationStatus::canTryAgain($delivery->fresh()));
    }

    #[Test]
    public function a_paused_website_holds_an_article_for_as_long_as_the_pause_and_resume_sends_it(): void
    {
        [$item, $delivery] = $this->sentByAvyoAndRetrying();
        $this->asOwner()->patch(route('channels.update', $this->channel), ['is_enabled' => '0'])->assertSessionHasNoErrors();

        // Two days of looks: no request, no attempt spent, and not given up.
        foreach (range(1, 5) as $look) {
            $this->retry($delivery);
        }
        $held = $delivery->fresh();
        $this->assertSame([DeliveryStatus::Retrying, 1, 0, WebhookPublisher::WAITING_FOR_RESUME],
            [$held->status, $held->attempts, $held->deferrals, $held->error]);
        Http::assertSentCount(1);

        $this->asOwner()->patch(route('channels.update', $this->channel), ['is_enabled' => '1'])->assertSessionHasNoErrors();

        $due = $delivery->fresh()->next_attempt_at;
        $this->assertNotNull($due);
        $this->assertTrue($due->lessThanOrEqualTo(now()));
        Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $job): bool => $job->deliveryId === $delivery->id && $job->scheduledFor === $due->toIso8601String());
        $this->answer = 200;
        app(WebhookPublisher::class)->attempt($delivery->fresh(), $due);
        $this->assertPublished($item, $delivery);
    }

    #[Test]
    public function a_passing_test_leaves_an_ordinary_backoff_alone_and_a_real_attempt_ends_a_wait(): void
    {
        [, $retrying] = $this->sentByAvyoAndRetrying();
        $rung = $retrying->fresh()->next_attempt_at;

        $this->test();
        $this->assertEquals($rung, $retrying->fresh()->next_attempt_at);

        // A wait for a broken website, then a real answer from it.
        $this->channel->forceFill(['verified_at' => null])->save();
        $this->retry($retrying);
        $this->assertSame(1, $retrying->fresh()->deferrals);
        $this->channel->forceFill(['verified_at' => now()])->save();
        $this->retry($retrying);
        $this->assertSame([2, 0], [$retrying->fresh()->attempts, $retrying->fresh()->deferrals]);
    }

    #[Test]
    public function an_article_whose_attempt_holds_its_lock_during_the_test_is_looked_at_again(): void
    {
        $item = $this->draft();
        $this->engineSchedule($item);
        $this->travel(1)->hours();
        $delivery = app(ArticleSchedules::class)->dispatch($item)[0];
        $this->channel->forceFill(['verified_at' => null])->save();
        app(WebhookPublisher::class)->attempt($delivery);
        $this->assertSame(WebhookPublisher::WAITING_FOR_WEBSITE, $delivery->fresh()->error);

        $lock = Cache::lock('webhook-delivery:'.$delivery->id, WebhookPublisher::lockSeconds());
        $this->assertTrue($lock->get());
        $this->test();
        $lock->release();

        Queue::assertPushed(ResumeHeldArticlesJob::class, fn (ResumeHeldArticlesJob $job): bool => $job->channelId === $this->channel->id);
        (new ResumeHeldArticlesJob($this->channel->id))->handle(app(HeldArticles::class));
        $due = $delivery->fresh()->next_attempt_at;
        $this->assertNotNull($due);
        app(WebhookPublisher::class)->attempt($delivery->fresh(), $due);
        $this->assertPublished($item, $delivery);
    }

    #[Test]
    public function a_wordpress_article_held_by_a_failed_test_is_sent_when_the_article_test_passes(): void
    {
        $this->channel->delete();
        $this->project->update(['website_url' => 'https://website.test']);
        $wordpress = Channel::factory()->create(['type' => ChannelType::WordPress, 'is_enabled' => true, 'verified_at' => now(),
            'config' => ['page_receiver_base' => 'https://website.test/wp-json/avyo/v1', 'username' => 'publisher', 'article_publishing_verified' => true],
            'secret' => 'application-password']);
        $item = $this->draft();
        $this->engineSchedule($item);
        $this->travel(1)->hours();
        $delivery = app(ArticleSchedules::class)->dispatch($item)[0];
        $publisher = app(WordPressPublisher::class);

        $this->responder = fn () => Http::response(['error' => 'Down for maintenance.'], 500);
        $publisher->attempt($publisher->ping($wordpress, $this->project));
        $this->assertFalse($wordpress->fresh()->config['article_publishing_verified']);
        $publisher->attempt($delivery->fresh());
        $this->assertSame([0, WebhookPublisher::WAITING_FOR_WEBSITE], [$delivery->fresh()->attempts, $delivery->fresh()->error]);

        $this->responder = function (Request $request) {
            if (($request['event'] ?? null) === 'ping') {
                return Http::response(['contract' => 1, 'delivery_id' => $request['delivery_id'], 'capabilities' => ['article_publish' => true]]);
            }
            $content = $request['content'];
            unset($content['published_at']);

            return Http::response(['contract' => 1, 'delivery_id' => $request['delivery_id'], 'content_id' => $content['id'], 'object_id' => '123',
                'status' => 'published', 'public_url' => 'https://website.test/useful-article/',
                'content_hash' => hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
        };
        $publisher->attempt($publisher->ping($wordpress, $this->project));

        $due = $delivery->fresh()->next_attempt_at;
        $this->assertNotNull($due);
        $this->assertTrue($due->lessThanOrEqualTo(now()));
        $publisher->attempt($delivery->fresh(), $due);
        $this->assertPublished($item, $delivery);
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

    #[Test]
    public function a_handed_back_attempt_cannot_be_cancelled_resumed_or_paused_into_a_corner(): void
    {
        [$item, $delivery] = $this->sentByAvyoAndRetrying();
        $this->project->update(['autopublish' => false]);
        app(ArticleSchedules::class)->followProject($this->project);
        $this->retry($delivery);
        $version = $this->scheduleOf($item)->version;

        foreach (['cancel', 'resume', 'pause'] as $action) {
            try {
                app(ArticleSchedules::class)->change($item, $version, $action);
                $this->fail("{$action} must be refused.");
            } catch (ValidationException $refusal) {
                $this->assertSame('Approve it or send it back instead.', $refusal->getMessage());
            }
        }
        $this->assertTrue(ArticleSchedules::awaitingOwnerAfterAttempt($this->scheduleOf($item)));
    }

    #[Test]
    public function pausing_a_handed_back_article_that_was_never_attempted_pauses_it(): void
    {
        $item = $this->draft();
        $this->engineSchedule($item);
        $this->travel(1)->hours();
        $delivery = app(ArticleSchedules::class)->dispatch($item)[0];
        $item->forceFill(['factcheck' => ['passed' => false]])->save();
        app(WebhookPublisher::class)->attempt($delivery);
        $this->assertSame(0, $delivery->fresh()->attempts);

        $schedule = app(ArticleSchedules::class)->change($item, $this->scheduleOf($item)->version, 'pause');

        $this->assertSame(['paused', null], [$schedule->status, $schedule->delivery_id]);
    }

    #[Test]
    public function resuming_a_website_edited_while_paused_tests_it_and_the_passing_test_sends_the_article(): void
    {
        [$item, $delivery] = $this->sentByAvyoAndRetrying();
        $this->asOwner()->patch(route('channels.update', $this->channel), ['is_enabled' => '0'])->assertSessionHasNoErrors();
        $this->retry($delivery);
        $this->asOwner()->patch(route('channels.update', $this->channel), ['config' => ['endpoint' => 'https://moved.test/articles']])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->channel->fresh()->verified_at);

        $this->asOwner()->patch(route('channels.update', $this->channel), ['is_enabled' => '1'])
            ->assertInertiaFlash('toast.message', 'Resumed. Testing the connection…');

        // No longer "paused": it waits for the test now running.
        $this->assertSame(WebhookPublisher::WAITING_FOR_WEBSITE, $delivery->fresh()->error);
        $test = WebhookDelivery::query()->whereNull('content_item_id')->latest('created_at')->firstOrFail();
        $this->answer = 200;
        app(WebhookPublisher::class)->attempt($test);
        $due = $delivery->fresh()->next_attempt_at;
        $this->assertNotNull($due);
        app(WebhookPublisher::class)->attempt($delivery->fresh(), $due);
        $this->assertPublished($item, $delivery);
    }

    #[Test]
    public function an_attempt_waiting_on_a_paused_website_can_be_sent_back_and_is_published_once_approved(): void
    {
        [$item, $delivery] = $this->sentByAvyoAndRetrying();
        $this->asOwner()->patch(route('channels.update', $this->channel), ['is_enabled' => '0'])->assertSessionHasNoErrors();
        $this->retry($delivery);
        $this->assertSame(WebhookPublisher::WAITING_FOR_RESUME, $delivery->fresh()->error);

        $this->asOwner()->post(route('content.reject', $item), ['reason' => 'off_brand', 'note' => 'Warmer, please.'])
            ->assertSessionHasNoErrors();

        // Withdrawn, but still the attempt the next send reconciles with.
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->dispatch_key);
        $this->assertTrue(ArticleSchedules::awaitingOwnerAfterAttempt($this->scheduleOf($item)));
        $this->assertSame(ContentItemState::Draft, $item->fresh()->state);

        $this->asOwner()->patch(route('channels.update', $this->channel), ['is_enabled' => '1'])->assertSessionHasNoErrors();
        $this->asOwner()->post("/content/{$item->id}/approve")->assertSessionHasNoErrors();
        $this->answer = 200;
        app(WebhookPublisher::class)->attempt($delivery->fresh());

        $this->assertPublished($item, $delivery);
        $this->assertSame(1, WebhookDelivery::query()->where('content_item_id', $item->id)->count());
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_pause_starts_the_broken_website_day_afresh(): void
    {
        [, $delivery] = $this->sentByAvyoAndRetrying();
        $this->channel->forceFill(['verified_at' => null])->save();
        $this->retry($delivery);
        $this->assertSame(1, $delivery->fresh()->deferrals);

        $this->channel->forceFill(['is_enabled' => false])->save();
        $this->retry($delivery);

        $this->assertSame([WebhookPublisher::WAITING_FOR_RESUME, 0], [$delivery->fresh()->error, $delivery->fresh()->deferrals]);
    }

    #[Test]
    public function the_website_is_read_afresh_when_deciding_to_wait(): void
    {
        $item = $this->draft();
        $this->engineSchedule($item);
        $this->travel(1)->hours();
        $delivery = app(ArticleSchedules::class)->dispatch($item)[0]->load('channel');
        $this->assertTrue($delivery->channel->is_enabled);

        Channel::query()->whereKey($this->channel->id)->update(['is_enabled' => false]);

        $this->assertTrue(app(ArticleDeliveryGuard::class)->websiteUnusable($delivery));
    }

    #[Test]
    public function the_dead_letter_and_the_hand_back_commit_together_or_not_at_all(): void
    {
        [, $delivery] = $this->sentByAvyoAndRetrying();
        $this->project->update(['autopublish' => false]);
        app(ArticleSchedules::class)->followProject($this->project);
        ArticleSchedule::saving(function (ArticleSchedule $schedule): void {
            if ($schedule->isDirty('blocked_code')) {
                throw new RuntimeException('The hand-back failed.');
            }
        });

        try {
            $this->retry($delivery);
            $this->fail('The hand-back was meant to fail.');
        } catch (RuntimeException) {
        }

        $this->assertSame(DeliveryStatus::Retrying, $delivery->fresh()->status);
    }

    #[Test]
    public function moving_a_waiting_article_to_another_website_sends_it_there(): void
    {
        [$item, $waiting] = $this->sentByAvyoAndRetrying();
        $this->asOwner()->patch(route('channels.update', $this->channel), ['is_enabled' => '0'])->assertSessionHasNoErrors();
        $this->retry($waiting);
        $this->assertSame(WebhookPublisher::WAITING_FOR_RESUME, $waiting->fresh()->error);

        // The owner gives up on the paused website and picks another one.
        $other = Channel::factory()->create(['type' => ChannelType::Webhook, 'is_enabled' => true, 'secret' => 'other-secret',
            'config' => ['endpoint' => 'https://other.test/articles'], 'verified_at' => now()]);
        app(ArticleSchedules::class)->save($this->owner, $item->fresh(),
            [...$this->slot(), 'expected_version' => $this->scheduleOf($item)->version, 'local_date' => now()->addDay()->toDateString(), 'channel_id' => $other->id]);

        // The old attempt stays with the old website; the new one gets its own.
        $this->assertNull($this->scheduleOf($item)->delivery_id);
        $this->travel(2)->days();
        $sent = app(ArticleSchedules::class)->dispatch($item->fresh());
        $this->assertCount(1, $sent);
        $this->assertNotSame($waiting->id, $sent[0]->id);
        $this->assertSame($other->id, $sent[0]->channel_id);

        $this->answer = 200;
        app(WebhookPublisher::class)->attempt($sent[0]);
        $this->assertPublished($item, $sent[0]);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://other.test/'));
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

    private function asOwner(): self
    {
        return $this->actingAs($this->owner)->withSession(['current_project_id' => $this->project->id]);
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
