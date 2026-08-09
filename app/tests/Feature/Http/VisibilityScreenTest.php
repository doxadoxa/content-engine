<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\LlmPrompt;
use App\Models\LlmVisibilityAnswer;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\VisibilityReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The prompt analysis screen.
 *
 * Mostly about one distinction the screen has to keep: a null score and a zero
 * score look the same in a percentage and mean opposite things. Everything on
 * this page is read from {@see VisibilityReport}, so a test that
 * the controller preserves the null is a test that the whole chain does.
 */
final class VisibilityScreenTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'name' => 'Cleaning Point',
            'website_url' => 'https://cleaningpoint.net',
            'default_locale' => 'en',
            'locales' => ['pt-PT', 'ru'],
        ]);

        $this->user = User::factory()->create();
        $this->user->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function it_renders(): void
    {
        $this->actingAs($this->user)
            ->get('/visibility')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('visibility/index'));
    }

    #[Test]
    public function a_project_that_has_never_been_asked_scores_null_not_zero(): void
    {
        LlmPrompt::factory()->for($this->project)->create(['text' => 'unasked', 'locale' => 'en']);

        $this->actingAs($this->user)
            ->get('/visibility')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Zero would read as "you appear in none of the answers", which
                // is a problem to act on. Nothing has been asked.
                ->where('summary.score', null)
                ->where('summary.monitored_prompts', 1)
                ->where('summary.answered', 0));
    }

    #[Test]
    public function it_reports_each_language_separately(): void
    {
        $ru = LlmPrompt::factory()->for($this->project)->create(['text' => 'ru one', 'locale' => 'ru']);
        $en = LlmPrompt::factory()->for($this->project)->create(['text' => 'en one', 'locale' => 'en']);

        LlmVisibilityAnswer::factory()->for($this->project)->for($ru, 'prompt')->mentioned()->create();
        LlmVisibilityAnswer::factory()->for($this->project)->for($en, 'prompt')->create();

        $this->actingAs($this->user)
            ->get('/visibility')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // The whole reason the feature is shaped this way: visible in
                // Russian, invisible in English, and a single 50% would have
                // described neither.
                ->where('by_locale.0.locale', 'en')
                ->where('by_locale.0.score', 0)
                ->where('by_locale.1.locale', 'ru')
                ->where('by_locale.1.score', 100)
                ->where('summary.score', 50));
    }

    #[Test]
    public function a_directory_is_shown_as_a_source_but_not_as_a_competitor(): void
    {
        $prompt = LlmPrompt::factory()->for($this->project)->create(['text' => 'one', 'locale' => 'en']);

        LlmVisibilityAnswer::factory()->for($this->project)->for($prompt, 'prompt')->create([
            'citations' => [
                ['url' => 'https://www.trustpilot.com/review/x', 'title' => 'Reviews'],
                ['url' => 'https://superlimpa.pt/', 'title' => 'Superlimpa'],
                ['url' => 'https://cleaningpoint.net/journal/x', 'title' => 'Us'],
            ],
        ]);

        $this->actingAs($this->user)
            ->get('/visibility')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sources.0.host', 'trustpilot.com')
                ->where('sources.0.is_aggregator', true)
                // A review site dominating the citations is a real finding —
                // the assistants do not know the businesses directly — but it
                // is not a business taking the customer.
                ->where('competitors.0.host', 'superlimpa.pt')
                ->count('competitors', 1));
    }

    #[Test]
    public function another_projects_answers_are_not_visible(): void
    {
        $other = Project::factory()->create();

        // Written *as* the other tenant, because the scope refuses a
        // cross-tenant write — which is the guarantee being tested.
        app(CurrentProject::class)->run($other, function () use ($other): void {
            $theirs = LlmPrompt::factory()->for($other)->create(['text' => 'theirs', 'locale' => 'en']);
            LlmVisibilityAnswer::factory()->for($other)->for($theirs, 'prompt')->mentioned()->create();
        });

        $this->actingAs($this->user)
            ->get('/visibility')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->count('prompts', 0)
                // Not 100%. The scope fails closed everywhere else in this
                // codebase; a screen that reads through a report object rather
                // than a scoped query is exactly where that would be lost.
                ->where('summary.score', null));
    }
}
