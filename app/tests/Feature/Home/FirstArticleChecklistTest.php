<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Billing\Entitlements;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\OnboardingStatus;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** Home's "Get your first article live": business, website, first article. */
final class FirstArticleChecklistTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T08:00:00Z'));
        Queue::fake();
        $this->project = Project::factory()->create(['timezone' => 'UTC', 'onboarding_status' => OnboardingStatus::Active]);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function with_no_website_the_second_step_is_current_and_publishing_waits_for_it(): void
    {
        $draft = ContentItem::factory()->draft()->create(['title' => 'First post']);

        $this->home($this->owner)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('first_article.state', 'in_progress')
            ->where('first_article.done', 1)
            ->where('first_article.steps.0.state', 'done')
            ->where('first_article.steps.1.state', 'current')
            ->where('first_article.steps.1.action.label', 'Connect your website')
            ->where('first_article.steps.1.action.href', '/channels')
            ->where('first_article.steps.2.state', 'todo')
            ->where('first_article.steps.2.article.id', $draft->id)
            ->where('first_article.steps.2.can_publish', false)
            ->where('first_article.steps.2.reason', 'Connect your website first.'));
    }

    #[Test]
    public function with_a_connected_website_the_first_ready_article_can_be_published_now(): void
    {
        $this->connect();
        ContentItem::factory()->create(['state' => ContentItemState::Queued, 'title' => 'Still writing']);
        $draft = ContentItem::factory()->draft()->create(['title' => 'Ready one']);

        $this->home($this->owner)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('first_article.done', 2)
            ->where('first_article.steps.1.state', 'done')
            ->where('first_article.steps.1.health', 'connected')
            ->where('first_article.steps.2.state', 'current')
            ->where('first_article.steps.2.article.id', $draft->id)
            ->where('first_article.steps.2.article.presentation.label', 'Waiting for you')
            ->where('first_article.steps.2.can_publish', true)
            ->where('first_article.steps.2.reason', null));
    }

    #[Test]
    public function an_operator_sees_the_steps_but_not_the_button(): void
    {
        $this->connect();
        ContentItem::factory()->draft()->create();
        $operator = User::factory()->create();
        $operator->projects()->attach($this->project, ['role' => 'operator']);

        $this->home($operator)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('first_article.steps.2.can_publish', false)
            ->where('first_article.steps.2.reason', 'Only the business owner can publish.'));
    }

    #[Test]
    public function during_the_preview_the_checklist_shows_and_asks_for_a_plan(): void
    {
        $this->connect();
        ContentItem::factory()->draft()->create();
        $subscription = ProjectSubscription::query()->where('project_id', $this->project->id)->first();
        $subscription === null
            ? ProjectSubscription::factory()->forProject($this->project)->plan('preview')->create()
            : $subscription->forceFill(['plan' => 'preview'])->save();
        app(Entitlements::class)->forget();

        $this->home($this->owner)->assertInertia(fn (AssertableInertia $page) => $page
            ->whereNot('preview', null)
            ->where('first_article.steps.2.can_publish', false)
            ->where('first_article.steps.2.reason', "Your plan doesn't include publishing yet.")
            ->where('first_article.steps.2.fix.href', '/billing'));
    }

    #[Test]
    public function once_live_it_collapses_to_one_line_and_then_goes_away(): void
    {
        $this->connect();
        $live = ContentItem::factory()->create(['state' => ContentItemState::Published, 'title' => 'Live one',
            'public_url' => 'https://example.test/live-one', 'published_at' => now()->subHours(2)]);

        $this->home($this->owner)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('first_article.state', 'live')
            ->where('first_article.article.id', $live->id)
            ->where('first_article.article.url', 'https://example.test/live-one'));

        $this->travel(2)->days();

        $this->home($this->owner)->assertInertia(fn (AssertableInertia $page) => $page->where('first_article', null));
    }

    /** @return TestResponse<Response> */
    private function home(User $user): TestResponse
    {
        return $this->actingAs($user)->withSession(['project_id' => $this->project->id])->get('/home')->assertOk();
    }

    private function connect(): void
    {
        Channel::factory()->create(['type' => ChannelType::Webhook, 'name' => 'Main site',
            'config' => ['endpoint' => 'https://receiver.test/articles'], 'is_enabled' => true, 'verified_at' => now()]);
    }
}
