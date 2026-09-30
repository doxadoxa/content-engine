<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Billing\Entitlements;
use App\Content\ArticleScore;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\PublishToChannels;
use App\Publishing\WebhookPublisher;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** "Publish now": the owner's press is the approval, and it goes out once. */
final class PublishNowTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Channel $channel;

    private ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T08:00:00Z'));
        Queue::fake();
        $this->project = Project::factory()->create(['autopublish' => false, 'timezone' => 'UTC']);
        app(CurrentProject::class)->set($this->project);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        $this->channel = Channel::factory()->create(['type' => ChannelType::Webhook,
            'config' => ['endpoint' => 'https://receiver.test/articles'], 'is_enabled' => true, 'verified_at' => now()]);
        $this->item = ContentItem::factory()->draft()->create(['body_html' => '<p>A useful article.</p>', 'factcheck' => ['passed' => true]]);
        $this->mock(ArticleScore::class, function (MockInterface $mock): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('for');
            $expectation->andReturn(['score' => 100, 'publishable' => true, 'blocking' => [], 'checks' => []]);
        });
    }

    #[Test]
    public function a_finished_draft_is_approved_and_sent_right_away(): void
    {
        $this->actingAs($this->owner)->post("/content/{$this->item->id}/publish-now")
            ->assertRedirect()->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Sending your article to your website now.');

        $this->assertSame(ContentItemState::Approved, $this->item->fresh()?->state);
        $this->assertSame(1, DB::table('article_approval_records')->where('content_item_id', $this->item->id)->count());
        $schedule = ArticleSchedule::query()->where('content_item_id', $this->item->id)->firstOrFail();
        $this->assertSame('dispatching', $schedule->status);
        $this->assertSame($this->channel->id, $schedule->channel_id);
        $this->assertSame(1, WebhookDelivery::query()->where('content_item_id', $this->item->id)->count());
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        $this->assertSame('sending', app(ArticleSchedules::class)->props($this->item->fresh() ?? $this->item)['presentation']['key']);
    }

    #[Test]
    public function a_scheduled_article_goes_now_and_the_scheduler_does_not_send_it_again(): void
    {
        $this->item->forceFill(['state' => ContentItemState::Approved])->save();
        ArticleSchedule::query()->create([
            'content_item_id' => $this->item->id, 'channel_id' => $this->channel->id,
            'publish_at' => CarbonImmutable::parse('2026-09-20T09:00:00Z'), 'local_date' => '2026-09-20', 'local_time' => '09:00',
            'timezone' => 'UTC', 'mode' => 'review_first', 'held_for_review' => false, 'origin' => 'engine', 'status' => 'active', 'version' => 1,
        ]);

        $this->actingAs($this->owner)->post("/content/{$this->item->id}/publish-now")->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::parse('2026-09-20T10:00:00Z'));
        /** @var PendingCommand $command */
        $command = $this->artisan('publish:approved', ['project' => $this->project->slug]);
        $command->assertSuccessful()->run();

        $this->assertSame(1, WebhookDelivery::query()->where('content_item_id', $this->item->id)->count());
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        $this->assertSame(2, ArticleSchedule::query()->firstOrFail()->version);
    }

    #[Test]
    public function only_the_owner_can_publish_now(): void
    {
        $operator = User::factory()->create();
        $operator->projects()->attach($this->project, ['role' => 'operator']);

        $this->actingAs($operator)->withSession(['project_id' => $this->project->id])
            ->post("/content/{$this->item->id}/publish-now")->assertForbidden();

        $this->assertSame(ContentItemState::Draft, $this->item->fresh()?->state);
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function without_a_website_it_says_so_and_approves_nothing(): void
    {
        $this->channel->forceFill(['verified_at' => null])->save();

        $this->actingAs($this->owner)->post("/content/{$this->item->id}/publish-now")
            ->assertSessionHasErrors(['publish' => 'Connect your website first.']);

        $this->assertSame(ContentItemState::Draft, $this->item->fresh()?->state);
        $this->assertSame(0, DB::table('article_approval_records')->count());
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function a_plan_that_cannot_publish_is_refused_in_plain_words(): void
    {
        $this->preview();

        $this->actingAs($this->owner)->post("/content/{$this->item->id}/publish-now")
            ->assertSessionHasErrors(['publish' => "Your plan doesn't include publishing yet."]);

        $this->assertSame(ContentItemState::Draft, $this->item->fresh()?->state);
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function an_article_already_on_its_way_is_not_sent_twice(): void
    {
        $this->actingAs($this->owner)->post("/content/{$this->item->id}/publish-now")->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post("/content/{$this->item->id}/publish-now")
            ->assertSessionHasErrors(['publish' => "It's already on its way."]);

        $this->assertSame(1, WebhookDelivery::query()->count());
        $this->assertSame(DeliveryStatus::Pending, WebhookDelivery::query()->firstOrFail()->status);
    }

    #[Test]
    public function a_person_publishing_now_overrules_a_failed_fact_check_on_an_automatic_project(): void
    {
        $this->project->forceFill(['autopublish' => true])->save();
        $this->item->forceFill(['factcheck' => ['passed' => false]])->save();

        $this->actingAs($this->owner)->post("/content/{$this->item->id}/publish-now")->assertSessionHasNoErrors();

        $schedule = ArticleSchedule::query()->where('content_item_id', $this->item->id)->firstOrFail();
        $this->assertFalse($schedule->approved_by_avyo);
        Http::fake(['receiver.test/*' => Http::response(['public_url' => 'https://example.test/article'])]);
        app(WebhookPublisher::class)->attempt(WebhookDelivery::query()->sole());

        $this->assertSame(DeliveryStatus::Delivered, WebhookDelivery::query()->sole()->status);
        $this->assertSame(ContentItemState::Published, $this->item->fresh()?->state);
    }

    #[Test]
    public function a_press_refused_at_the_last_step_leaves_nothing_behind(): void
    {
        $schedule = app(ArticleSchedules::class)->save($this->owner, $this->item, [
            'expected_version' => null, 'local_date' => '2026-09-20', 'local_time' => '09:00', 'hold' => true,
        ]);
        $this->partialMock(PublishToChannels::class, function (MockInterface $mock): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('publishToSelected');
            $expectation->andReturnNull();
        });

        $this->actingAs($this->owner)->post("/content/{$this->item->id}/publish-now")->assertSessionHasErrors('publish');

        $this->assertSame(ContentItemState::Draft, $this->item->fresh()?->state);
        $this->assertSame(0, DB::table('article_approval_records')->count());
        $after = $schedule->fresh();
        $this->assertNotNull($after);
        $this->assertSame([1, 'active', true, '2026-09-20'], [$after->version, $after->status, $after->held_for_review, $after->local_date]);
    }

    #[Test]
    public function another_businesses_article_cannot_be_published_now(): void
    {
        $other = Project::factory()->create();
        $theirs = app(CurrentProject::class)->run($other, fn (): ContentItem => ContentItem::factory()->draft()->create(['factcheck' => ['passed' => true]]));

        $status = $this->actingAs($this->owner)->post("/content/{$theirs->id}/publish-now")->status();

        $this->assertContains($status, [403, 404]);
        $this->assertSame(ContentItemState::Draft, ContentItem::acrossProjects()->findOrFail($theirs->id)->state);
        $this->assertSame(0, WebhookDelivery::acrossProjects()->count());
    }

    private function preview(): void
    {
        $subscription = ProjectSubscription::query()->where('project_id', $this->project->id)->first();
        $subscription === null
            ? ProjectSubscription::factory()->forProject($this->project)->plan('preview')->create()
            : $subscription->forceFill(['plan' => 'preview'])->save();
        app(Entitlements::class)->forget();
    }
}
