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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
    public function exact_questions_show_the_same_latest_provider_outcomes_as_the_score_without_buying_checks(): void
    {
        Queue::fake();
        Http::fake();
        $prompt = LlmPrompt::factory()->for($this->project)->create([
            'text' => 'Which cleaning companies serve Lisbon?', 'locale' => 'en', 'intent' => 'buying',
        ]);
        LlmVisibilityAnswer::factory()->for($this->project)->for($prompt, 'prompt')->create([
            'platform' => 'chat_gpt', 'mentioned' => true, 'asked_on' => Carbon::today()->subDays(3),
        ]);
        foreach (['chat_gpt' => false, 'gemini' => true, 'claude' => null] as $platform => $mentioned) {
            LlmVisibilityAnswer::factory()->for($this->project)->for($prompt, 'prompt')->create([
                'platform' => $platform, 'mentioned' => $mentioned, 'asked_on' => Carbon::today()->subDay(),
            ]);
        }

        $this->actingAs($this->user)->get('/visibility')->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.score', 50)->where('summary.answered', 2)
            ->has('prompts', 1)->where('prompts.0.text', $prompt->text)->where('prompts.0.locale', 'en')
            ->where('prompts.0.intent', 'buying')->has('prompts.0.checks', 4)
            ->where('prompts.0.checks.0.platform', 'chat_gpt')->where('prompts.0.checks.0.label', 'ChatGPT')
            ->where('prompts.0.checks.0.mentioned', false)->where('prompts.0.checks.0.status', 'answered')
            ->where('prompts.0.checks.0.asked_on', Carbon::today()->subDay()->toDateString())
            ->where('prompts.0.checks.1.mentioned', true)->where('prompts.0.checks.1.status', 'answered')
            ->where('prompts.0.checks.2.mentioned', null)->where('prompts.0.checks.2.status', 'no_answer')
            ->where('prompts.0.checks.3.mentioned', null)->where('prompts.0.checks.3.status', 'not_checked')
            ->where('prompts.0.checks.3.asked_on', null));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function legacy_evidence_uses_the_same_latest_answer_as_the_score_and_filters_unsafe_citations(): void
    {
        $prompt = LlmPrompt::factory()->for($this->project)->create([
            'text' => 'Which cleaning companies serve Lisbon?', 'locale' => 'en',
        ]);
        LlmVisibilityAnswer::factory()->for($this->project)->for($prompt, 'prompt')->create([
            'platform' => 'chat_gpt', 'mentioned' => true,
            'asked_on' => Carbon::today()->subDay(), 'excerpt' => 'An older response.',
            'citations' => [['url' => 'https://older.example.test', 'title' => 'Old source']],
        ]);
        LlmVisibilityAnswer::factory()->for($this->project)->for($prompt, 'prompt')->create([
            'platform' => 'chat_gpt', 'mentioned' => false,
            'asked_on' => Carbon::today(), 'excerpt' => 'Latest recorded response.',
            'citations' => [
                ['url' => 'https://safe.example.test/source', 'title' => 'Safe source'],
                ['url' => 'javascript:alert(1)', 'title' => 'Unsafe'],
                ['url' => 'data:text/html,unsafe', 'title' => 'Unsafe data'],
            ],
        ]);

        $this->actingAs($this->user)->get('/visibility')->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.score', 0)
            ->where('prompts.0.checks.0.mentioned', false)
            ->where('prompts.0.checks.0.excerpt', 'Latest recorded response.')
            ->has('prompts.0.checks.0.citations', 1)
            ->where('prompts.0.checks.0.citations.0.url', 'https://safe.example.test/source'));
    }

    #[Test]
    public function a_blank_latest_legacy_excerpt_is_exposed_as_unavailable_not_an_older_response(): void
    {
        $prompt = LlmPrompt::factory()->for($this->project)->create(['text' => 'one', 'locale' => 'en']);
        LlmVisibilityAnswer::factory()->for($this->project)->for($prompt, 'prompt')->create([
            'platform' => 'chat_gpt', 'mentioned' => true,
            'asked_on' => Carbon::today()->subDay(), 'excerpt' => 'Old answer',
        ]);
        LlmVisibilityAnswer::factory()->for($this->project)->for($prompt, 'prompt')->create([
            'platform' => 'chat_gpt', 'mentioned' => false,
            'asked_on' => Carbon::today(), 'excerpt' => " \n ",
        ]);

        $this->actingAs($this->user)->get('/visibility')->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.score', 0)
            ->where('prompts.0.checks.0.excerpt', null)
            ->count('prompts.0.checks.0.citations', 0));
    }

    /**
     * An assistant that has stopped answering says so instead of sitting there.
     *
     * The report keeps the freshest answer per prompt *per platform*, so a
     * platform whose calls are being refused does not vanish from the panel —
     * it keeps serving whatever it last managed. That is the right storage
     * behaviour and a terrible thing to render silently: on 19 August 2026 the
     * headline averaged three assistants measured that morning with ChatGPT
     * measured eleven days earlier, and every tile looked equally current.
     */
    #[Test]
    public function an_assistant_left_behind_by_the_latest_sweep_is_marked_stale(): void
    {
        $one = LlmPrompt::factory()->for($this->project)->create(['text' => 'one', 'locale' => 'en']);
        $two = LlmPrompt::factory()->for($this->project)->create(['text' => 'two', 'locale' => 'en']);

        // Measured in this morning's sweep. Two answers, so it sorts first and
        // the assertions below can name a row rather than search for one.
        foreach ([$one, $two] as $prompt) {
            LlmVisibilityAnswer::factory()->for($this->project)->for($prompt, 'prompt')->create([
                'platform' => 'gemini',
                'asked_on' => Carbon::today(),
                'mentioned' => true,
            ]);
        }

        // Refused ever since the vendor retired its model name, so its newest
        // reading is eleven days old — and still inside the 30-day window the
        // report reads, which is exactly why it is still on the screen.
        LlmVisibilityAnswer::factory()->for($this->project)->for($one, 'prompt')->create([
            'platform' => 'chat_gpt',
            'asked_on' => Carbon::today()->subDays(11),
            'mentioned' => true,
        ]);

        $this->actingAs($this->user)
            ->get('/visibility')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('by_platform.0.platform', 'gemini')
                ->where('by_platform.0.stale', false)
                ->where('by_platform.1.platform', 'chat_gpt')
                ->where('by_platform.1.stale', true)
                ->where('by_platform.1.last_asked_on', Carbon::today()->subDays(11)->toDateString()));
    }

    /**
     * And one merely bought a day later is not, which is the ordinary case.
     *
     * `max_answers_per_run` stops a sweep at the budget and the rest is bought
     * on the next run, so a day of spread across the panel is the system doing
     * what it was built to do. Flagging that would put a warning on a healthy
     * screen every week until nobody read it.
     */
    #[Test]
    public function an_assistant_finished_a_day_later_is_not_called_stale(): void
    {
        $prompt = LlmPrompt::factory()->for($this->project)->create(['text' => 'one', 'locale' => 'en']);

        LlmVisibilityAnswer::factory()->for($this->project)->for($prompt, 'prompt')->create([
            'platform' => 'gemini',
            'asked_on' => Carbon::today(),
            'mentioned' => true,
        ]);

        LlmVisibilityAnswer::factory()->for($this->project)->for($prompt, 'prompt')->create([
            'platform' => 'chat_gpt',
            'asked_on' => Carbon::today()->subDay(),
            'mentioned' => true,
        ]);

        $this->actingAs($this->user)
            ->get('/visibility')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('by_platform.0.stale', false)
                ->where('by_platform.1.stale', false));
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
