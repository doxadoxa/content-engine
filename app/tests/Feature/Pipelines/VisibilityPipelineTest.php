<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\PipelineRunStatus;
use App\Models\LlmPrompt;
use App\Models\LlmVisibilityAnswer;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\VisibilityPipeline;
use App\Research\Contracts\KeywordSource;
use App\Research\FakeKeywordSource;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\Contracts\LlmVisibilityGateway;
use App\Visibility\FakeLlmVisibility;
use App\Visibility\VisibilityReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Visibility in AI answers, end to end.
 *
 * The centre of this file is the locale question, because it is the reason the
 * feature is shaped the way it is. A project selling in Portugal to Portuguese,
 * English and Russian speakers is visible or invisible three separate times, and
 * measuring only the market's own language reports 0% about a channel that is
 * demonstrably sending customers.
 */
final class VisibilityPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeLlmVisibility $assistants;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'name' => 'Cleaning Point',
            'website_url' => 'https://cleaningpoint.net',
            'market' => 'pt',
            'default_locale' => 'en',
            'locales' => ['pt-PT', 'ru'],
        ]);
        app(CurrentProject::class)->set($this->project);

        /** @var FakeLlmVisibility $assistants */
        $assistants = app(LlmVisibilityGateway::class);
        $this->assistants = $assistants;

        /** @var FakeModelGateway $models */
        $models = app(ModelGateway::class);
        $this->models = $models;

        config()->set('queue.default', 'sync');
        config()->set('visibility.prompts_per_locale', 2);
    }

    // ------------------------------------------------- the locale question

    #[Test]
    public function it_writes_prompts_for_every_locale_the_project_publishes_in(): void
    {
        $this->scriptPrompts();

        $this->runPipeline();

        // Three languages, not one. Measuring only 'pt' because the market is
        // Portugal is exactly the bug: this project got a customer from ChatGPT
        // answering in Russian while its score read 0%.
        $this->assertSame(
            ['en', 'pt-PT', 'ru'],
            LlmPrompt::query()->distinct()->orderBy('locale')->pluck('locale')->all(),
        );
    }

    #[Test]
    public function the_score_is_broken_out_per_locale(): void
    {
        $this->scriptPrompts();
        $this->assistants->withPlatforms(['chat_gpt']);

        // Named in Russian, invisible in the other two.
        $this->assistants->willAnswer('chat_gpt', 'ru prompt 1', 'Рекомендуем Cleaning Point.');
        $this->assistants->willAnswer('chat_gpt', 'ru prompt 2', 'Cleaning Point — хороший выбор.');

        $this->runPipeline();

        $byLocale = collect(VisibilityReport::latest()->byLocale())->keyBy('locale');

        $this->assertSame(100.0, $byLocale['ru']['score']);
        $this->assertSame(0.0, $byLocale['en']['score']);
        $this->assertSame(0.0, $byLocale['pt-PT']['score']);

        // And the headline is the average of the answers, not of the locales —
        // two of six answers named the brand.
        $this->assertSame(33.3, VisibilityReport::latest()->score());
    }

    #[Test]
    public function the_markets_own_language_is_measured_even_when_it_is_not_a_listed_locale(): void
    {
        // Exactly this project: it sells in Portugal, its locale list says
        // English and Russian, and since articles began following their
        // keyword's language every one of them is written in Portuguese. The
        // sweep was measuring two languages the site does not publish and
        // missing the one it does.
        $this->assertNotContains('pt', $this->project->locales);

        /** @var FakeKeywordSource $keywords */
        $keywords = app(KeywordSource::class);
        $keywords->willSpeak('pt');

        $this->models->willAnswer(array_map(
            static fn (string $locale): string => "PROMPT: {$locale} prompt 1 | buying\nPROMPT: {$locale} prompt 2 | comparison",
            ['en', 'pt-PT', 'ru', 'pt'],
        ));

        $this->runPipeline();

        $this->assertContains('pt', LlmPrompt::query()->distinct()->pluck('locale')->all());
    }

    #[Test]
    public function a_locale_listed_twice_is_measured_once(): void
    {
        $this->project->forceFill(['default_locale' => 'en', 'locales' => ['en', 'pt-PT']])->save();
        $this->scriptPrompts();

        $this->runPipeline();

        $this->assertSame(['en', 'pt-PT'], LlmPrompt::query()->distinct()->orderBy('locale')->pluck('locale')->all());
    }

    // ------------------------------------------------------ what counts as seen

    #[Test]
    public function a_declined_answer_is_not_counted_as_a_miss(): void
    {
        $this->scriptPrompts();
        $this->assistants->withPlatforms(['chat_gpt', 'gemini']);

        // Six prompts. ChatGPT names the brand every time; Gemini is down and
        // returns nothing at all.
        foreach (['en', 'pt-PT', 'ru'] as $locale) {
            foreach ([1, 2] as $n) {
                $this->assistants->willAnswer('chat_gpt', "{$locale} prompt {$n}", 'Try Cleaning Point.');
                $this->assistants->willAnswer('gemini', "{$locale} prompt {$n}", null);
            }
        }

        $this->runPipeline();

        $report = VisibilityReport::latest();

        // 100%, not 50%. Counting a refusal as "answered without us" makes an
        // outage at one vendor look like a collapse in visibility, and this
        // number's whole job is to be trusted when it moves.
        $this->assertSame(100.0, $report->score());
        $this->assertSame(6, $report->answered());
        $this->assertSame(6, $report->declined());
    }

    #[Test]
    public function nothing_measured_scores_null_rather_than_zero(): void
    {
        $this->scriptPrompts();

        // Prompts exist; no sweep has run.
        LlmPrompt::factory()->for($this->project)->create(['text' => 'unasked', 'locale' => 'en']);

        // Null and 0 render identically and mean opposite things: "you are in
        // none of the answers" versus "nothing has been asked yet".
        $this->assertNull(VisibilityReport::latest()->score());
    }

    #[Test]
    public function being_cited_counts_even_when_the_brand_is_not_named(): void
    {
        $this->scriptPrompts();
        $this->assistants->withPlatforms(['chat_gpt']);

        $this->assistants->willAnswer(
            'chat_gpt',
            'en prompt 1',
            'Several local firms cover this well.',
            [['url' => 'https://cleaningpoint.net/journal/x', 'title' => 'A guide']],
        );

        $this->runPipeline();

        $mentioned = LlmVisibilityAnswer::query()->where('mentioned', true)->count();

        // The assistant sent the customer to the site without naming the
        // business. That is visibility.
        $this->assertSame(1, $mentioned);
    }

    // ------------------------------------------------------------- the money

    #[Test]
    public function it_does_not_pay_twice_for_the_same_answer_on_the_same_day(): void
    {
        $this->scriptPrompts();
        $this->assistants->withPlatforms(['chat_gpt']);

        $this->runPipeline();
        $after = count($this->assistants->asked());

        $this->runPipeline();

        // An engine tick that overlaps the previous one must not re-buy answers
        // it already has — and the unique index would reject the write only
        // after the money was spent.
        $this->assertSame($after, count($this->assistants->asked()));
        $this->assertSame(6, LlmVisibilityAnswer::query()->count());
    }

    #[Test]
    public function the_budget_stops_a_sweep_and_says_how_much_it_dropped(): void
    {
        $this->scriptPrompts();
        $this->assistants->withPlatforms(['chat_gpt', 'gemini']);

        $run = $this->runPipeline(['max_answers' => 4]);

        $this->assertSame(4, LlmVisibilityAnswer::query()->count());

        // Twelve were due, four were bought. A truncated sweep that reported a
        // score without saying so would read as though it covered everything.
        $this->assertSame(8, $run->context['visibility.skipped_for_budget'] ?? null);
    }

    #[Test]
    public function one_assistant_failing_does_not_take_the_others_with_it(): void
    {
        $this->scriptPrompts();
        $this->assistants->withPlatforms(['chat_gpt', 'gemini']);

        $run = $this->runPipeline();

        // Four vendors, four separate outages. A project should get the answers
        // it can have rather than none.
        $this->assertSame(PipelineRunStatus::Completed, $run->status);
        $this->assertSame(12, LlmVisibilityAnswer::query()->count());
    }

    #[Test]
    public function a_sweep_split_across_two_days_by_the_budget_is_scored_whole(): void
    {
        $this->scriptPrompts();
        $this->assistants->withPlatforms(['chat_gpt']);

        // Every answer names the brand. The budget only allows two today.
        foreach (['en', 'pt-PT', 'ru'] as $locale) {
            foreach ([1, 2] as $n) {
                $this->assistants->willAnswer('chat_gpt', "{$locale} prompt {$n}", 'Try Cleaning Point.');
            }
        }

        $this->travelTo(now()->subDay());
        $this->runPipeline(['max_answers' => 2]);

        $this->travelBack();
        $this->runPipeline();

        // Six of six, not four of four. Reading only the newest day would score
        // the project on whichever fraction happened to land last, so the
        // headline would swing on the size of the budget rather than on
        // anything about the brand.
        $report = VisibilityReport::latest();

        $this->assertSame(6, $report->answered());
        $this->assertSame(100.0, $report->score());
    }

    #[Test]
    public function every_assistant_failing_is_a_failure_rather_than_a_quiet_zero(): void
    {
        $this->scriptPrompts();
        $this->assistants->withPlatforms(['chat_gpt'])->failEverything();

        $run = $this->runPipeline();

        // One vendor down is absorbed; all of them down is one cause, usually
        // ours. Passing would write no answers and leave the screen showing the
        // previous sweep's score as though it were current.
        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame(0, LlmVisibilityAnswer::query()->count());
    }

    // ------------------------------------------------------ the instrument

    #[Test]
    public function a_locale_that_is_one_short_gets_one_prompt_not_a_new_set(): void
    {
        config()->set('visibility.prompts_per_locale', 3);

        LlmPrompt::factory()->for($this->project)->count(2)->create(['locale' => 'en']);

        // One reply, offering more than the shortfall — the model is asked for
        // what is missing, and must not be able to inflate the set by offering
        // extra.
        $this->models->willAnswer([
            "PROMPT: en topped up | buying\nPROMPT: en spare one | buying\nPROMPT: en spare two | buying",
            'PROMPT: pt-PT one | buying',
            'PROMPT: ru one | buying',
        ]);

        $this->runPipeline();

        // Three, not five. This asked "does it need any?" and then wrote a full
        // set, so a locale holding four of five ended up with nine — and the
        // set inflates on every run that finds it short, taking the cost of a
        // sweep with it at four assistants per prompt.
        $this->assertSame(3, LlmPrompt::query()->where('locale', 'en')->count());
    }

    #[Test]
    public function a_stale_set_is_retired_rather_than_added_to(): void
    {
        config()->set('visibility.prompts_per_locale', 2);
        config()->set('visibility.prompts_stale_after_days', 30);

        $this->travelTo(now()->subDays(200));
        LlmPrompt::factory()->for($this->project)->count(2)->create(['locale' => 'en']);
        $this->travelBack();

        $this->scriptPrompts();

        $this->runPipeline();

        // Two active, not four. The prompts are the instrument, and doubling
        // one is not the same as replacing it.
        $this->assertSame(2, LlmPrompt::query()->where('locale', 'en')->where('is_active', true)->count());

        // The old ones are deactivated rather than deleted: answers already
        // recorded against them are a measurement that happened.
        $this->assertSame(2, LlmPrompt::query()->where('locale', 'en')->where('is_active', false)->count());
    }

    #[Test]
    public function prompts_are_not_rewritten_on_every_run(): void
    {
        $this->scriptPrompts();

        $this->runPipeline();
        $first = LlmPrompt::query()->orderBy('id')->pluck('text')->all();

        $this->scriptPrompts('different');

        $this->runPipeline();

        // A score measured against a different question each week is not a
        // trend. Prompts are the instrument, and an instrument that changes
        // between readings measures nothing.
        $this->assertSame($first, LlmPrompt::query()->orderBy('id')->pluck('text')->all());
    }

    #[Test]
    public function an_over_long_prompt_is_dropped_rather_than_truncated(): void
    {
        $long = str_repeat('a very long question ', 20);

        $this->models->willAnswer(array_map(
            static fn (string $locale): string => "PROMPT: {$long} | buying\nPROMPT: {$locale} short one | learning",
            ['en', 'pt-PT', 'ru'],
        ));

        $this->runPipeline();

        // The endpoint caps a prompt at 500 characters, and shortening one
        // changes the question being measured.
        $this->assertSame(3, LlmPrompt::query()->count());

        foreach (LlmPrompt::query()->pluck('text') as $text) {
            $this->assertLessThanOrEqual(300, mb_strlen((string) $text));
        }
    }

    #[Test]
    public function the_assistants_are_asked_about_the_projects_market(): void
    {
        $this->scriptPrompts();
        $this->assistants->withPlatforms(['chat_gpt']);

        $this->runPipeline();

        // Steers the assistant's own web search. "Best cleaning service" has a
        // different answer in Portugal than in the United States, and the
        // default is the United States.
        foreach ($this->assistants->asked() as $call) {
            $this->assertSame('pt', $call['country']);
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Two prompts per locale, in the order GeneratePrompts asks for them.
     */
    private function scriptPrompts(string $flavour = ''): void
    {
        $this->models->willAnswer(array_map(
            static fn (string $locale): string => implode("\n", [
                "PROMPT: {$locale} prompt 1{$flavour} | buying",
                "PROMPT: {$locale} prompt 2{$flavour} | comparison",
            ]),
            // The order GeneratePrompts walks them: default locale first.
            ['en', 'pt-PT', 'ru'],
        ));
    }

    /** @param array<string, mixed> $input */
    private function runPipeline(array $input = []): PipelineRun
    {
        return app(PipelineRunner::class)
            ->start(VisibilityPipeline::key(), $this->project, $input)
            ->refresh();
    }
}
