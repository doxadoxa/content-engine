<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Content\ArticleScore;
use App\Enums\ChannelType;
use App\Enums\ProjectStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** The Approve toast says what approving did, not what it hoped for. */
final class ApprovalToastTest extends TestCase
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
        $this->item = ContentItem::factory()->draft()->create(['body_html' => '<p>Useful.</p>', 'factcheck' => ['passed' => true]]);
        $this->mock(ArticleScore::class, function (MockInterface $mock): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('for');
            $expectation->andReturn(['score' => 100, 'publishable' => true, 'blocking' => [], 'checks' => []]);
        });
    }

    #[Test]
    public function without_a_schedule_it_offers_the_two_next_steps(): void
    {
        $this->approve()->assertInertiaFlash('toast.message', 'Article approved. Pick a date, or publish it now.');
    }

    #[Test]
    public function a_future_date_is_named(): void
    {
        $this->schedule('2026-09-18T09:00:00Z');

        $this->approve()->assertInertiaFlash('toast.message', 'Article approved. It publishes Fri 18 Sep at 09:00.');
    }

    #[Test]
    public function a_due_article_says_it_is_being_sent(): void
    {
        $this->schedule('2026-09-15T07:00:00Z');

        $this->approve()->assertInertiaFlash('toast.message', 'Article approved. Sending it to your website now.');
    }

    #[Test]
    public function a_blocked_article_says_why_it_is_waiting(): void
    {
        $this->schedule('2026-09-15T07:00:00Z');
        $this->project->forceFill(['status' => ProjectStatus::Paused])->save();

        $this->approve()
            ->assertInertiaFlash('toast.type', 'info')
            ->assertInertiaFlash('toast.message', 'Article approved, but it is waiting. Content work is paused for this business. Resume it to publish.');
    }

    /** @return TestResponse<Response> */
    private function approve(): TestResponse
    {
        return $this->actingAs($this->owner)->post("/content/{$this->item->id}/approve")->assertRedirect()->assertSessionHasNoErrors();
    }

    private function schedule(string $at): void
    {
        $when = CarbonImmutable::parse($at);
        ArticleSchedule::query()->create([
            'content_item_id' => $this->item->id, 'channel_id' => $this->channel->id, 'publish_at' => $when,
            'local_date' => $when->toDateString(), 'local_time' => $when->format('H:i'), 'timezone' => 'UTC',
            'mode' => 'review_first', 'held_for_review' => false, 'origin' => 'manager', 'status' => 'active', 'version' => 1,
        ]);
    }
}
