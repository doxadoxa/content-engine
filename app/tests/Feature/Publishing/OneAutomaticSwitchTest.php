<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Content\ArticleScore;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Publishing\Articles\ArticleApproval;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\WordPressPublisher;
use App\Support\Content\ManagerContent;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whether an article waits for a person is the project's one answer, plus
 * the owner's hold on a single article — not a third switch on the website.
 */
final class OneAutomaticSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T08:00:00Z'));
        Queue::fake();
        $this->project = Project::factory()->create([
            'autopublish' => true, 'timezone' => 'UTC', 'website_url' => 'https://website.test',
            'onboarding' => ['article_automation_started_at' => now()->subDay()->toIso8601String()],
        ]);
        app(CurrentProject::class)->set($this->project);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        // The website's old checkbox left off, as it was for most owners.
        $this->channel = Channel::factory()->create(['type' => ChannelType::Webhook, 'is_enabled' => true,
            'config' => ['endpoint' => 'https://receiver.test/articles'], 'verified_at' => now(), 'autopublish' => false]);
        $this->mock(ArticleScore::class, function (MockInterface $mock): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('for');
            $expectation->andReturn(['score' => 100, 'publishable' => true, 'blocking' => [], 'checks' => []]);
        });
    }

    #[Test]
    public function an_automatic_project_publishes_through_a_tested_website_whatever_its_old_switch_says(): void
    {
        $workflow = ManagerContent::workflow($this->project);
        $this->assertTrue($workflow['ready']);
        $this->assertSame('automatic', $workflow['mode']);

        $item = $this->draft();
        $schedule = $this->engineSchedule($item);
        $this->assertSame('automatic', $schedule->mode);
        $this->assertSame('active', $schedule->status);
        $this->assertSame($this->channel->id, $schedule->channel_id);

        $this->travel(1)->hours();
        $this->assertCount(1, app(ArticleSchedules::class)->dispatch($item));
        $this->assertSame(ContentItemState::Approved, $item->fresh()->state);
        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    #[Test]
    public function a_held_article_waits_for_approval_on_an_automatic_project(): void
    {
        $item = $this->draft();
        // One usable website, so the form sends none and the server uses it.
        $this->asOwner()->put("/content/{$item->id}/schedule", [
            'expected_version' => null, 'local_date' => '2026-09-15', 'local_time' => '10:00', 'hold' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $schedule = ArticleSchedule::query()->where('content_item_id', $item->id)->firstOrFail();
        $this->assertTrue($schedule->held_for_review);
        $this->assertSame('review_first', $schedule->mode);
        $this->assertSame($this->channel->id, $schedule->channel_id);
        $this->assertTrue(app(ArticleSchedules::class)->props($item->fresh())['schedule']['held']);

        $this->travelTo(CarbonImmutable::parse('2026-09-15T10:00:00Z'));
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($item));
        $this->assertSame(ContentItemState::Draft, $item->fresh()->state);
        $this->assertStringContainsString('Review and approve', (string) $schedule->fresh()->blocked_reason);

        app(ArticleApproval::class)->approve($item);
        $this->assertCount(1, app(ArticleSchedules::class)->dispatch($item));
    }

    #[Test]
    public function a_review_first_project_waits_for_approval(): void
    {
        $this->project->update(['autopublish' => false]);
        $item = $this->draft();
        $schedule = $this->engineSchedule($item);
        $this->assertSame('review_first', $schedule->mode);
        $this->assertSame('review_first', app(ArticleSchedules::class)->props($item->fresh())['default_mode']);

        $this->travel(1)->hours();
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($item));
        $this->assertSame(ContentItemState::Draft, $item->fresh()->state);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function switching_to_review_first_applies_to_waiting_articles_and_an_approved_one_then_publishes(): void
    {
        $item = $this->draft();
        $schedule = $this->engineSchedule($item);

        $this->asOwner()->patch("/projects/{$this->project->id}", [...$this->settings(), 'autopublish' => false])
            ->assertSessionHasNoErrors()->assertRedirect();

        $schedule->refresh();
        $this->assertSame('review_first', $schedule->mode);
        $this->assertFalse($schedule->held_for_review);
        $this->assertSame(2, $schedule->version);

        $this->travel(1)->hours();
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($item));
        $this->assertSame(ContentItemState::Draft, $item->fresh()->state);

        // The owner approves it by hand: it goes, rather than being told
        // automatic publishing is off.
        app(ArticleApproval::class)->approve($item);
        $this->assertCount(1, app(ArticleSchedules::class)->dispatch($item));
        $this->assertSame('dispatching', $schedule->fresh()->status);
    }

    #[Test]
    public function switching_to_automatic_releases_waiting_articles_but_not_held_ones(): void
    {
        $this->project->update(['autopublish' => false]);
        $waiting = $this->draft();
        $schedule = $this->engineSchedule($waiting);
        $held = $this->draft();
        $this->save($held, ['hold' => true, 'local_time' => '08:30']);

        $this->travel(1)->hours();
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($waiting));
        $this->assertSame('blocked', $schedule->fresh()->status);

        $this->asOwner()->patch("/projects/{$this->project->id}", [...$this->settings(), 'autopublish' => true])
            ->assertSessionHasNoErrors()->assertRedirect();

        $schedule->refresh();
        $this->assertSame('automatic', $schedule->mode);
        $this->assertSame('active', $schedule->status);
        $this->assertNull($schedule->blocked_reason);
        $this->assertSame('review_first', ArticleSchedule::query()->where('content_item_id', $held->id)->value('mode'));

        $this->assertCount(1, app(ArticleSchedules::class)->dispatch($waiting));
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($held));
        $this->assertSame(ContentItemState::Draft, $held->fresh()->state);
    }

    #[Test]
    public function a_new_date_on_a_review_first_project_keeps_an_earlier_hold(): void
    {
        $item = $this->draft();
        $this->save($item, ['hold' => true]);
        $this->project->update(['autopublish' => false]);
        app(ArticleSchedules::class)->followProject($this->project);

        // No checkbox on a review-first project, so no `hold` is sent.
        $schedule = $this->save($item, ['expected_version' => 1, 'local_time' => '11:00']);
        $this->assertTrue($schedule->held_for_review);

        $this->project->update(['autopublish' => true]);
        app(ArticleSchedules::class)->followProject($this->project);
        $this->assertSame('review_first', $schedule->fresh()->mode);
    }

    #[Test]
    public function a_ymyl_project_cannot_choose_automatic_publishing(): void
    {
        $this->project->update(['is_ymyl' => true, 'autopublish' => false]);

        $this->asOwner()->patch("/projects/{$this->project->id}", [...$this->settings(), 'autopublish' => true])
            ->assertSessionHasErrors('autopublish');
        $this->assertFalse($this->project->refresh()->autopublish);

        $this->asOwner()->patch("/projects/{$this->project->id}", [...$this->settings(), 'autopublish' => false])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_website_has_no_automatic_switch_of_its_own_any_more(): void
    {
        $this->assertFalse(Route::has('channels.autopublish'));

        $this->asOwner()->patch(route('channels.update', $this->channel), [
            'name' => $this->channel->name, 'type' => 'webhook', 'is_enabled' => true,
            'config' => ['endpoint' => 'https://receiver.test/articles'], 'autopublish' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->channel->refresh();
        $this->assertFalse($this->channel->autopublish);
        $this->assertNotNull($this->channel->verified_at);
    }

    #[Test]
    public function wordpress_is_usable_for_articles_once_its_article_test_passes(): void
    {
        $this->channel->delete();
        // Verified earlier by binding a tracked page, which does not prove
        // it can take articles; the article test is the one that does.
        $wordpress = Channel::factory()->create([
            'type' => ChannelType::WordPress,
            'config' => ['page_receiver_base' => 'https://website.test/wp-json/avyo/v1', 'username' => 'publisher'],
            'secret' => 'application-password',
            'verified_at' => now()->subDay(),
        ]);
        $this->assertFalse(ManagerContent::workflow($this->project->fresh())['ready']);

        Http::fake(fn (Request $request) => Http::response([
            'contract' => 1,
            'delivery_id' => $request['delivery_id'],
            'capabilities' => ['article_publish' => true],
        ]));
        $publisher = app(WordPressPublisher::class);
        $publisher->attempt($publisher->ping($wordpress, $this->project));

        $this->assertTrue(app(ArticleSchedules::class)->compatible($wordpress->refresh()));
        $this->assertTrue(ManagerContent::workflow($this->project->fresh())['ready']);
    }

    private function draft(): ContentItem
    {
        return ContentItem::factory()->draft()->create(['body_html' => '<p>A useful article.</p>', 'factcheck' => ['passed' => true]]);
    }

    private function engineSchedule(ContentItem $item): ArticleSchedule
    {
        $schedule = app(ArticleSchedules::class)->scheduleNew($item, now()->addHour());
        $this->assertNotNull($schedule);

        return $schedule;
    }

    /** @param  array<string, mixed>  $input */
    private function save(ContentItem $item, array $input): ArticleSchedule
    {
        /** @var array{expected_version: int|null, local_date: string, local_time: string, channel_id?: string|null, hold?: bool|null} $input */
        $input = ['expected_version' => null, 'local_date' => '2026-09-15', 'local_time' => '10:00', ...$input];

        return app(ArticleSchedules::class)->save($this->owner, $item, $input);
    }

    private function asOwner(): self
    {
        return $this->actingAs($this->owner)->withSession(['current_project_id' => $this->project->id]);
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ['name' => $this->project->name, 'slug' => $this->project->slug, 'timezone' => $this->project->timezone,
            'default_locale' => 'en', 'locales' => ['en'], 'status' => 'active'];
    }
}
