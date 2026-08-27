<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Enums\ContentItemState;
use App\Enums\PipelineRunStatus;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two things the article half can now be asked for.
 *
 * It could be asked for nothing at all before this. Every article came from a
 * `planning` run that fires once inside `ProjectLaunch`, is on no schedule, and
 * had no button anywhere in the product — so the composer on Home offered six
 * kinds of social post and the SEO half of a search-and-answer engine had no
 * verb of its own on any screen.
 */
final class ArticleVerbsTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // The runs are asserted as rows rather than executed: what these two
        // actions are responsible for is putting the right work in the queue,
        // and the pipelines themselves have their own tests.
        Queue::fake();

        $this->operator = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->users()->attach($this->operator);

        $this->actingAs($this->operator);
        $this->withSession(['project_id' => $this->project->getKey()]);
    }

    #[Test]
    public function a_typed_topic_becomes_an_article_the_engine_is_writing(): void
    {
        $this->post('/content/articles', [
            'prompt' => 'how often should a rented flat be deep cleaned',
        ])->assertRedirect();

        app(CurrentProject::class)->run($this->project, function (): void {
            $item = ContentItem::query()->roots()->firstOrFail();

            // The sentence becomes the target query, which is the field
            // `CompileBrief` builds the whole brief from — so a hand-typed
            // topic reaches the writer the same way a researched one does.
            $this->assertSame(
                'how often should a rented flat be deep cleaned',
                $item->target_query,
            );
            $this->assertSame(ContentItemState::Idea, $item->state);
            $this->assertSame($this->project->default_locale, $item->locale);

            $run = PipelineRun::query()->where('pipeline', 'generation')->firstOrFail();
            $this->assertSame($item->getKey(), $run->content_item_id);
        });
    }

    #[Test]
    public function a_topic_too_short_to_write_about_is_refused(): void
    {
        $this->post('/content/articles', ['prompt' => 'ok'])
            ->assertSessionHasErrors('prompt');

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(0, ContentItem::query()->roots()->count());
        });
    }

    #[Test]
    public function the_month_can_be_planned_from_the_screen(): void
    {
        $this->post('/content/plan')->assertRedirect();

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertTrue(
                PipelineRun::query()->where('pipeline', 'planning')->exists(),
                'Asking for a month has to start a planning run.',
            );
        });
    }

    #[Test]
    public function a_second_press_does_not_plan_the_month_twice(): void
    {
        app(CurrentProject::class)->run($this->project, function (): void {
            PipelineRun::query()->create([
                'pipeline' => 'planning',
                'status' => PipelineRunStatus::Running,
                'started_at' => now(),
            ]);
        });

        $this->post('/content/plan')->assertRedirect();

        app(CurrentProject::class)->run($this->project, function (): void {
            // Planning reads the whole keyword set and writes a month of units;
            // two of them racing would plan the same month twice.
            $this->assertSame(
                1,
                PipelineRun::query()->where('pipeline', 'planning')->count(),
            );
        });
    }

    #[Test]
    public function another_projects_planning_run_does_not_block_this_one(): void
    {
        $theirs = Project::factory()->create();

        app(CurrentProject::class)->run($theirs, function (): void {
            PipelineRun::query()->create([
                'pipeline' => 'planning',
                'status' => PipelineRunStatus::Running,
                'started_at' => now(),
            ]);
        });

        $this->post('/content/plan')->assertRedirect();

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertTrue(
                PipelineRun::query()->where('pipeline', 'planning')->exists(),
            );
        });
    }
}
