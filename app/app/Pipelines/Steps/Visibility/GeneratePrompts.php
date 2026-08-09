<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Visibility;

use App\Enums\PromptIntent;
use App\Models\BrandBrief;
use App\Models\LlmPrompt;
use App\Onboarding\SiteAnalyst;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Research\Contracts\KeywordSource;
use Illuminate\Support\Facades\Log;

/**
 * The questions a buyer would type, written once per language.
 *
 * **Per language is the point of this step, not a detail of it.** A project
 * selling in Portugal is not one audience: it answers in Portuguese, in English
 * for the expatriate half of its market, and — as this project found out from a
 * customer who arrived through ChatGPT — in Russian. Prompts written only in the
 * market's own language measure one of those three and report the total as zero,
 * which is precisely wrong. It says "no visibility" about a channel that is
 * demonstrably sending customers.
 *
 * So the locales come from the project's own settings — `default_locale` plus
 * `locales` — and every one of them gets its own prompts, in its own language.
 *
 * Prompts are then left alone. Regenerating them each run would mean each week's
 * score is measured against a different question, and a trend across those
 * measures nothing. They are rewritten only when a locale has too few, or when
 * they have gone stale — which is a repositioned business, not a schedule.
 */
class GeneratePrompts extends AbstractStep
{
    public function __construct(private readonly KeywordSource $keywords) {}

    public static function key(): string
    {
        return 'generate_prompts';
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $wanted = max(1, (int) $context->get('prompts_per_locale', config('visibility.prompts_per_locale', 5)));
        $locales = $this->locales($context);

        if ($locales === []) {
            // Not reachable through the wizard, which requires a locale. Said
            // out loud anyway, because the alternative is a 0% visibility score
            // whose real meaning is "we never asked anything".
            return StepResult::skip('This project has no locales, so there is nothing to ask in.');
        }

        $brief = BrandBrief::query()->where('is_active', true)->latest('version')->first();

        $written = 0;

        foreach ($locales as $locale) {
            $missing = $this->shortfall($locale, $wanted);

            if ($missing > 0) {
                $written += $this->writeFor($context, $locale, $missing, $brief);
            }
        }

        $context->remember('visibility.locales', $locales);
        $context->remember('visibility.prompts_written', $written);

        return StepResult::success(new PromptSetPayload($locales, $written));
    }

    /**
     * Every language a customer might ask in, deduplicated, default first.
     *
     * The project's own locales, plus the language its market is searched in —
     * and the second is not a nicety. Articles now follow their keyword's
     * language rather than the project setting, so this project publishes in
     * Portuguese while its locale list says English and Russian. Without this
     * the sweep measured visibility in two languages the site does not write
     * and missed the one it does.
     *
     * The market's language comes from the keyword source because that is what
     * already decides an article's language; asking anything else would let the
     * two disagree.
     *
     * @return list<string>
     */
    private function locales(StepContext $context): array
    {
        $project = $context->project;

        $market = $this->keywords->languageFor($project->market, $project->default_locale);

        return array_values(array_unique(array_filter(
            array_map(trim(...), [$project->default_locale, ...$project->locales, (string) $market]),
            static fn (string $locale): bool => $locale !== '',
        )));
    }

    /**
     * How many prompts this locale still needs.
     *
     * The shortfall, not the target. This asked "does it need any?" and then
     * wrote a full set, so a locale holding four of five ended up with nine —
     * and the set inflates on every run that finds it short, taking the cost of
     * a sweep with it at four assistants per prompt.
     *
     * A stale set is retired rather than added to, for the same reason: the
     * prompts are the instrument, and doubling one is not the same as
     * replacing it.
     */
    private function shortfall(string $locale, int $wanted): int
    {
        $active = LlmPrompt::query()->where('locale', $locale)->where('is_active', true);

        $days = max(1, (int) config('visibility.prompts_stale_after_days', 90));

        $stale = (clone $active)->count() > 0
            && (clone $active)->where('created_at', '>=', now()->subDays($days))->doesntExist();

        if ($stale) {
            // Deactivated rather than deleted: the answers already recorded
            // against them are a measurement that happened, and the trend line
            // they sit on should stay readable.
            (clone $active)->update(['is_active' => false]);

            return $wanted;
        }

        return max(0, $wanted - (clone $active)->count());
    }

    private function writeFor(StepContext $context, string $locale, int $wanted, ?BrandBrief $brief): int
    {
        $mix = PromptIntent::mix($wanted);

        $answer = $context->ask(
            'utility',
            implode("\n", array_filter([
                "The business: {$context->project->name}, selling in {$context->project->market}.",
                $brief === null ? null : "Positioning: {$brief->positioning}",
                $brief === null ? null : "Audience: {$brief->audience}",
                sprintf('Write exactly %d prompts in this order of intent: %s.', $wanted, implode(', ', array_map(
                    static fn (PromptIntent $intent): string => $intent->value,
                    $mix,
                ))),
            ])),
            implode("\n", [
                'You write the prompts a real person types into an AI assistant when they are looking for a '
                    .'service. One per line, in the form:',
                'PROMPT: the prompt text | buying',
                '',
                "Write every prompt in the language of the locale {$locale}. Not translated out of English — "
                    .'phrased the way somebody who speaks that language would actually type it.',
                'Never name the business being measured. A prompt that names it finds it every time and '
                    .'measures nothing.',
                'Keep each prompt under 300 characters. Output nothing but PROMPT: lines.',
            ]),
        );

        $written = 0;

        foreach ($this->parse($answer->text) as $index => [$text, $intent]) {
            if (mb_strlen($text) > 300) {
                // The API this feeds caps a prompt at 500 characters, and
                // truncating one changes the question being measured. Dropped
                // here rather than silently shortened three steps later.
                Log::info('Dropped an over-long generated prompt', ['locale' => $locale, 'length' => mb_strlen($text)]);

                continue;
            }

            // firstOrCreate because (project, locale, text) is unique, and a
            // model asked twice about one business returns overlapping
            // phrasings more often than not.
            $prompt = LlmPrompt::query()->firstOrCreate(
                ['locale' => $locale, 'text' => $text],
                ['intent' => $intent ?? $mix[$index % count($mix)], 'is_active' => true],
            );

            if ($prompt->wasRecentlyCreated) {
                $written++;
            }

            if ($written >= $wanted) {
                break;
            }
        }

        if ($written === 0) {
            // Not a failure: a locale can legitimately already have its full
            // set. Worth a line because the other cause is a model that ignored
            // the format, and that is invisible from the score alone.
            Log::warning('Wrote no new prompts for a locale', ['locale' => $locale]);
        }

        return $written;
    }

    /**
     * `PROMPT: text | intent` lines into pairs.
     *
     * The same shape {@see SiteAnalyst} uses, and for the same
     * reason: a model that drifts out of JSON produces something unparseable,
     * while a model that drifts out of this produces lines that are skipped.
     *
     * @return list<array{0: string, 1: PromptIntent|null}>
     */
    private function parse(string $text): array
    {
        $prompts = [];

        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            if (preg_match('/^PROMPT:\s*(.+)$/ui', trim($line), $matches) !== 1) {
                continue;
            }

            $parts = array_map(trim(...), explode('|', $matches[1]));
            $body = $parts[0];

            if ($body === '') {
                continue;
            }

            $prompts[] = [$body, PromptIntent::tryFrom(mb_strtolower($parts[1] ?? ''))];
        }

        return $prompts;
    }
}
