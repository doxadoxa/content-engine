<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\PostKind;
use App\Enums\SocialKpi;
use App\Models\ContentGoal;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Project;
use App\Models\User;
use App\Social\ActionBoard;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The social surface's Overview: a goal above a board.
 *
 * The board's columns are a *reading* of the state machine rather than a second
 * lifecycle beside it, so most of what is asserted here is that the reading
 * survives the states a real month passes through — including the two partial
 * cases that a naive "any approved means done" would get wrong.
 */
final class SocialOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-14 10:00:00');

        $this->operator = User::factory()->create();
        $this->project = Project::factory()->create(['weekly_target' => 3]);
        $this->project->users()->attach($this->operator);
        $this->actingAs($this->operator);
        $this->withSession(['project_id' => $this->project->getKey()]);
    }

    #[Test]
    public function a_month_with_no_goal_points_at_plan_rather_than_asking_for_a_number(): void
    {
        $this->get('/social?month=2026-08')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('social/index')
                ->where('goal', null)
                ->where('has_plan', false)
                // The KPI list moved to Plan with the form it belonged to. This
                // screen no longer asks an operator to invent a target from a
                // blank field; the assistant sizes one and they approve it.
                ->missing('kpis')
            );
    }

    #[Test]
    public function setting_a_goal_confirms_it_and_gives_the_month_a_denominator(): void
    {
        $this->post('/social/goal', [
            'month' => '2026-08',
            'kpi' => 'reach',
            'target' => 2500,
            'cadence' => 4,
        ])->assertRedirect();

        app(CurrentProject::class)->run($this->project, function (): void {
            $goal = ContentGoal::forMonth(Carbon::parse('2026-08-01'));

            $this->assertNotNull($goal);
            $this->assertSame(SocialKpi::Reach, $goal->kpi);
            $this->assertSame(2500, $goal->target);
            $this->assertTrue($goal->isConfirmed());
            // Four weeks of the cadence, not the number of weeks August
            // happens to have — the goal is a four-week promise.
            $this->assertSame(16, $goal->plannedPosts());

            // The week counter is arithmetic on the month, so it is the same
            // for an operator who set the goal on the 1st and one who set it
            // on the 20th. Both boundaries pinned, and the cap: the 29th to
            // the 31st are week four rather than a fifth nobody planned for.
            $this->assertSame(1, $goal->weekOf(Carbon::parse('2026-08-01')));
            $this->assertSame(1, $goal->weekOf(Carbon::parse('2026-08-07')));
            $this->assertSame(2, $goal->weekOf(Carbon::parse('2026-08-08')));
            $this->assertSame(4, $goal->weekOf(Carbon::parse('2026-08-22')));
            $this->assertSame(4, $goal->weekOf(Carbon::parse('2026-08-31')));
            $this->assertNull($goal->weekOf(Carbon::parse('2026-09-01')));
        });

        $this->get('/social?month=2026-08')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('goal.confirmed', true)
                ->where('goal.kpi', 'reach')
                // Weeks are seven days from the 1st, so the 14th is week two.
                ->where('goal.week_of', 2)
                ->where('progress.planned', 16)
                ->where('progress.shipped', 0)
                // Nothing in this engine reads an impression back, so the
                // figure is absent rather than a confident zero.
                ->where('goal.progress', null)
                ->whereNot('goal.needs', null)
            );
    }

    #[Test]
    public function a_goal_can_be_replaced_without_creating_a_second_one_for_the_month(): void
    {
        $this->post('/social/goal', [
            'month' => '2026-08', 'kpi' => 'reach', 'target' => 100, 'cadence' => 2,
        ])->assertRedirect();

        $this->post('/social/goal', [
            'month' => '2026-08', 'kpi' => 'followers', 'target' => 900, 'cadence' => 5,
        ])->assertRedirect();

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(1, ContentGoal::query()->count());

            $goal = ContentGoal::forMonth(Carbon::parse('2026-08-01'));
            $this->assertSame(SocialKpi::Followers, $goal->kpi);
            $this->assertSame(900, $goal->target);
        });
    }

    #[Test]
    public function a_target_of_nothing_is_not_a_goal(): void
    {
        $this->post('/social/goal', [
            'month' => '2026-08', 'kpi' => 'reach', 'target' => 0, 'cadence' => 3,
        ])->assertSessionHasErrors('target');

        $this->post('/social/goal', [
            'month' => '2026-08', 'kpi' => 'not-a-kpi', 'target' => 10, 'cadence' => 3,
        ])->assertSessionHasErrors('kpi');
    }

    /**
     * The three columns, over the states a real month actually passes through.
     *
     * The two partial cases are the point. An idea whose only written channel
     * was approved is *not* done — the plan asked for two — and an idea whose
     * channels are split between approved and drafted is not done either.
     */
    #[Test]
    public function the_board_reads_the_state_machine_rather_than_a_second_lifecycle(): void
    {
        app(CurrentProject::class)->run($this->project, function (): void {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create([
                'assistant_version' => 1,
            ]);

            $untouched = $this->idea($plan, 'untouched', '2026-08-03');
            $drafting = $this->idea($plan, 'drafting', '2026-08-04');
            $halfWritten = $this->idea($plan, 'half-written', '2026-08-05');
            $split = $this->idea($plan, 'split', '2026-08-06');
            $finished = $this->idea($plan, 'finished', '2026-08-07');

            $this->item($plan, $drafting, 'threads', ContentItemState::Draft);
            $this->item($plan, $drafting, 'x', ContentItemState::Generating);

            // One channel written and approved; the other never written.
            $this->item($plan, $halfWritten, 'threads', ContentItemState::Approved);

            $this->item($plan, $split, 'threads', ContentItemState::Approved);
            $this->item($plan, $split, 'x', ContentItemState::Draft);

            $this->item($plan, $finished, 'threads', ContentItemState::Published);
            $this->item($plan, $finished, 'x', ContentItemState::Approved);

            $cards = ActionBoard::cards($plan)->keyBy('id');

            $this->assertSame(ActionBoard::TODO, $cards[$untouched->getKey()]['column']);
            $this->assertSame(ActionBoard::IN_PROGRESS, $cards[$drafting->getKey()]['column']);
            $this->assertSame(ActionBoard::IN_PROGRESS, $cards[$halfWritten->getKey()]['column']);
            $this->assertSame(ActionBoard::IN_PROGRESS, $cards[$split->getKey()]['column']);
            $this->assertSame(ActionBoard::DONE, $cards[$finished->getKey()]['column']);
        });

        $this->get('/social?month=2026-08')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('has_plan', true)
                ->has('columns.todo', 1)
                ->has('columns.in_progress', 3)
                ->has('columns.done', 1)
                // Approved counts as shipped: whether a delivery window has
                // come round is the engine's business, not the operator's.
                ->where('progress.shipped', 4)
            );
    }

    #[Test]
    public function the_board_shows_only_the_live_proposals_ideas(): void
    {
        app(CurrentProject::class)->run($this->project, function (): void {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create([
                'assistant_version' => 2,
            ]);

            $this->idea($plan, 'superseded', '2026-08-03', version: 1);
            $this->idea($plan, 'current', '2026-08-04', version: 2);

            $cards = ActionBoard::cards($plan);

            $this->assertCount(1, $cards);
            $this->assertSame('current', $cards->first()['title']);
        });
    }

    #[Test]
    public function the_board_never_reads_a_relation_lazily(): void
    {
        app(CurrentProject::class)->run($this->project, function (): void {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create([
                'assistant_version' => 1,
            ]);

            for ($n = 0; $n < 12; $n++) {
                $idea = $this->idea($plan, "idea-{$n}", '2026-08-0'.(($n % 9) + 1));
                $this->item($plan, $idea, 'threads', ContentItemState::Draft);
            }

            // Lazy loading throws outside production, so a per-card
            // `->contentItems` would fail here rather than merely being slow.
            $this->assertCount(12, ActionBoard::cards($plan));
        });
    }

    #[Test]
    public function another_projects_month_is_not_visible_on_this_ones_board(): void
    {
        $other = Project::factory()->create();

        app(CurrentProject::class)->run($other, function () use ($other): void {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create([
                'assistant_version' => 1,
            ]);

            $this->idea($plan, 'theirs', '2026-08-03');

            ContentGoal::factory()->forMonth('2026-08-01')->confirmed()->create([
                'project_id' => $other->getKey(),
            ]);
        });

        $this->get('/social?month=2026-08')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('goal', null)
                ->where('has_plan', false)
                ->has('columns.todo', 0)
            );
    }

    private function idea(
        ContentPlan $plan,
        string $title,
        string $date,
        int $version = 1,
    ): ContentIdea {
        return $plan->contentIdeas()->create([
            'proposal_version' => $version,
            'idea_key' => str($title)->slug()->value(),
            'title' => $title,
            'kind' => PostKind::Take,
            'pillar' => 'Build in public',
            'thesis' => 'A reason to say it.',
            'evidence' => [],
            'goal' => 'trust',
            'audience' => 'founders',
            'angle' => null,
            'channels' => ['threads', 'x'],
            'scheduled_for' => $date,
        ]);
    }

    private function item(
        ContentPlan $plan,
        ContentIdea $idea,
        string $channel,
        ContentItemState $state,
    ): ContentItem {
        return ContentItem::factory()->create([
            'content_plan_id' => $plan->getKey(),
            'content_idea_id' => $idea->getKey(),
            'type' => ContentItemType::SocialPost,
            'channel_type' => $channel,
            'state' => $state,
            'scheduled_for' => $idea->scheduled_for,
        ]);
    }
}
