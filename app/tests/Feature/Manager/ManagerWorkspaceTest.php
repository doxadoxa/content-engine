<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Enums\ContentItemState;
use App\Enums\OnboardingStatus;
use App\Feedback\Contracts\SearchConsoleGateway;
use App\Feedback\FakeSearchConsole;
use App\Feedback\ManagerResults;
use App\Feedback\Measurements\ReadResult;
use App\Feedback\Measurements\ReadStatus;
use App\Feedback\Measurements\SearchRow;
use App\Feedback\Measurements\SynchronizePageMeasurements;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ManagerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', 'UTC'));
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->create(['timezone' => 'Europe/Lisbon', 'autopublish' => true, 'onboarding_status' => OnboardingStatus::Active]);
        $this->project->users()->attach($this->owner, ['role' => 'owner']);
        app(CurrentProject::class)->set($this->project);
        $this->actingAs($this->owner)->withSession(['project_id' => $this->project->id]);
    }

    #[Test]
    public function a_future_approved_article_is_upcoming_instead_of_a_review_problem(): void
    {
        $article = $this->article(ContentItemState::Approved);
        $this->schedule($article, '2026-09-20');
        $review = $this->article(ContentItemState::Draft);
        $this->schedule($review, '2026-09-21', 'review_first');

        $this->withHeaders($this->partial('manager'))->get('/home')->assertOk()
            ->assertJsonPath('props.manager.mode', 'automatic')
            ->assertJsonPath('props.manager.scheduled', 2)
            ->assertJsonPath('props.manager.needs_review', 1)
            ->assertJsonPath('props.manager.upcoming.0.id', $article->id)
            ->assertJsonPath('props.manager.attention.0.id', $review->id);
    }

    #[Test]
    public function calendar_follows_actual_local_publication_date_instead_of_the_original_idea_date(): void
    {
        $article = $this->article(ContentItemState::Approved);
        $article->update(['scheduled_for' => '2026-09-16']);
        $this->schedule($article, '2026-10-02');

        $this->get('/calendar?month=2026-09-01')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('units', 0));
        $this->get('/calendar?month=2026-10-01')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('units.0.id', $article->id)->where('units.0.calendar_date', '2026-10-02')
            ->where('units.0.scheduled_for', '2026-09-16')->where('timezone', 'Europe/Lisbon'));
    }

    #[Test]
    public function canceling_a_schedule_keeps_the_article_in_the_unscheduled_list(): void
    {
        $article = $this->article(ContentItemState::Draft);
        $article->update(['scheduled_for' => '2026-09-20']);
        $this->schedule($article, '2026-09-20')->update(['status' => 'canceled']);

        $this->get('/calendar?month=2026-09-01')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('units', 0)->where('unscheduled.0.id', $article->id)->where('unscheduled.0.calendar_date', null));
    }

    #[Test]
    public function content_views_show_the_corresponding_work_without_other_projects(): void
    {
        $scheduled = $this->article(ContentItemState::Approved);
        $this->schedule($scheduled, '2026-09-20');
        $this->article(ContentItemState::Draft);
        $theirs = Project::factory()->create();
        app(CurrentProject::class)->run($theirs, function (): void {
            $this->schedule($this->article(ContentItemState::Approved), '2026-09-20')->update(['status' => 'blocked']);
        });

        $this->get('/content?view=scheduled')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('view', 'scheduled')->has('items.data', 1)->where('items.data.0.id', $scheduled->id));
        $this->get('/content?view=review')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('view', 'review')->has('items.data', 1)->where('items.data.0.state', 'draft'));
    }

    #[Test]
    public function automatic_drafts_without_a_passing_check_are_visible_for_review_before_the_due_tick(): void
    {
        foreach ([[], ['passed' => false], ['passed' => 'true']] as $check) {
            $article = $this->article(ContentItemState::Draft);
            $article->forceFill(['factcheck' => $check])->save();
            $this->schedule($article, '2026-09-20');
        }
        $safe = $this->article(ContentItemState::Draft);
        $safe->forceFill(['factcheck' => ['passed' => true]])->save();
        $this->schedule($safe, '2026-09-20');
        $this->get('/content?view=review')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('items.data', 3));
        $this->withHeaders($this->partial('manager'))->get('/home')->assertOk()->assertJsonPath('props.manager.needs_review', 3);
    }

    #[Test]
    public function the_calendar_keeps_the_publication_instant_when_the_business_timezone_changes(): void
    {
        $article = $this->article(ContentItemState::Approved);
        $schedule = $this->schedule($article, '2026-10-01');
        $schedule->update(['publish_at' => Carbon::parse('2026-10-01 00:30:00', 'UTC'), 'local_time' => '01:30']);
        $this->project->update(['timezone' => 'America/Los_Angeles']);

        $this->get('/calendar?month=2026-09-01')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('units.0.calendar_date', '2026-09-30')->where('units.0.publication.schedule.local_time', '17:30')
            ->where('units.0.publication.schedule.timezone', 'America/Los_Angeles'));
        $this->get('/calendar?month=2026-10-01')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('units', 0));
        $this->get('/calendar')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('month', '2026-09-01')->has('units', 1));
        $this->assertSame('2026-10-01', $schedule->refresh()->local_date);
        $this->assertSame('2026-10-01 00:30:00', $schedule->publish_at->utc()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function publishing_readiness_requires_both_explicit_opt_in_and_one_compatible_destination(): void
    {
        $this->withHeaders($this->partial('manager'))->get('/home')->assertOk()
            ->assertJsonPath('props.manager.workflow.ready', false)->assertJsonPath('props.manager.workflow.opted_in', false);
        $this->project->update(['onboarding' => ['article_automation_started_at' => now()->toIso8601String()]]);
        $this->withHeaders($this->partial('manager'))->get('/home')->assertOk()->assertJsonPath('props.manager.workflow.ready', false);
        Channel::factory()->create(['type' => 'webhook', 'config' => ['endpoint' => 'https://website.test/hook'], 'verified_at' => now(), 'is_enabled' => true, 'autopublish' => true, 'secret' => 'test-secret']);
        $this->withHeaders($this->partial('manager'))->get('/home')->assertOk()->assertJsonPath('props.manager.workflow.ready', true);
    }

    #[Test]
    public function saving_an_explicit_publishing_preference_enables_only_future_articles_and_retains_the_first_choice_time(): void
    {
        $old = $this->article(ContentItemState::Approved);
        $settings = ['name' => $this->project->name, 'slug' => $this->project->slug, 'timezone' => $this->project->timezone,
            'default_locale' => 'en', 'locales' => ['en'], 'status' => 'active'];
        $this->patch('/projects/'.$this->project->id, $settings)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertArrayNotHasKey('article_automation_started_at', $this->project->refresh()->onboarding);
        $this->patch('/projects/'.$this->project->id, [...$settings, 'autopublish' => true])->assertSessionHasNoErrors()->assertRedirect();
        $stamp = $this->project->refresh()->onboarding['article_automation_started_at'];
        $this->assertIsString($stamp);
        $this->assertNull($old->articleSchedule);
        $this->travel(1)->hour();
        $this->patch('/projects/'.$this->project->id, [...$settings, 'autopublish' => false])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($stamp, $this->project->refresh()->onboarding['article_automation_started_at']);
        $this->assertFalse($this->project->autopublish);
        $this->assertSame(0, ArticleSchedule::query()->count());
    }

    #[Test]
    public function results_do_not_turn_legacy_direct_visits_into_search_clicks(): void
    {
        SitePage::factory()->create(['tracked_at' => now()]);
        $this->get('/performance')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.pages.0.current.search.clicks', null)->where('report.pages.0.current.search.impressions', null)
            ->where('report.pages.0.current.search.observed_days', 0)->missing('results'));
        $this->withHeaders($this->partial('results'))->get('/home')->assertOk()->assertJsonPath('props.results.search.clicks', null);
    }

    #[Test]
    public function observed_zero_is_distinct_from_an_unobserved_page_and_another_tenant(): void
    {
        $page = SitePage::factory()->create(['tracked_at' => now()]);
        SitePage::factory()->create(['tracked_at' => now()]);
        /** @var FakeSearchConsole $search */
        $search = app(SearchConsoleGateway::class);
        $search->willReadPages(new ReadResult(ReadStatus::Complete, [new SearchRow($page->url, '2026-09-01', 0, 0, null)]));
        app(SynchronizePageMeasurements::class)->sync($this->project);
        $other = Project::factory()->create();
        app(CurrentProject::class)->set($other);
        $results = app(ManagerResults::class)->for($this->project);
        $this->assertSame(0, $results['search']['clicks']);
        $this->assertSame(1, $results['search']['observed_pages']);
        $this->assertSame(2, $results['search']['tracked_pages']);
        $this->assertNull($results['search']['previous_clicks']);
        $this->assertSame($other->id, app(CurrentProject::class)->id());
        $this->assertNull(app(ManagerResults::class)->for($other)['search']['clicks']);
    }

    private function article(ContentItemState $state): ContentItem
    {
        return ContentItem::factory()->create(['state' => $state, 'locale' => 'en']);
    }

    private function schedule(ContentItem $article, string $date, string $mode = 'automatic'): ArticleSchedule
    {
        return ArticleSchedule::query()->create(['content_item_id' => $article->id, 'local_date' => $date, 'local_time' => '09:00',
            'timezone' => 'Europe/Lisbon', 'publish_at' => Carbon::parse($date.' 09:00', 'Europe/Lisbon')->utc(),
            'mode' => $mode, 'status' => 'active', 'origin' => 'manager', 'version' => 1]);
    }

    /** @return array<string, string> */
    private function partial(string $prop): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Inertia-Partial-Component' => 'home/index', 'X-Inertia-Partial-Data' => $prop];
    }
}
