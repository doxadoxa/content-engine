<?php

declare(strict_types=1);

namespace App\Visibility;

use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Research\DataForSeo\DataForSeoClient;
use App\Visibility\Contracts\LlmVisibilityGateway;
use Illuminate\Support\Facades\Log;

/**
 * DataForSEO's AI Optimization API, LLM Responses half.
 *
 * One endpoint per assistant — `/v3/ai_optimization/{platform}/llm_responses/live`
 * — each taking a prompt and a model name and answering with the text plus the
 * sources it says it used. Billed as a small task fee plus whatever the
 * underlying provider charged, which the response reports back as `money_spent`
 * and this records, because a measurement whose own cost is invisible is one
 * nobody can decide to run less often.
 *
 * `web_search` is on. Without it the assistant answers about the brand from
 * training data, which measures what it remembered months ago rather than what
 * it would tell a customer today — and the entire point of this is the second
 * thing.
 *
 * Confirmed against live responses on 2026-08-07. The nesting below —
 * `result[0].items[0].sections[].text` with `annotations[]` alongside — is what
 * both ChatGPT and Perplexity actually return.
 *
 * Cost is worth knowing before scheduling this. One ChatGPT answer with web
 * search on cost $0.029; the same question to Perplexity cost $0.005. A sweep
 * of five prompts in three locales across four assistants is sixty answers, so
 * roughly a dollar a run — which is why `max_answers_per_run` exists and why
 * this is a weekly job rather than a nightly one.
 *
 * Everything the suite asserts runs through {@see FakeLlmVisibility}.
 */
class DataForSeoLlmVisibility implements LlmVisibilityGateway
{
    public function __construct(private readonly DataForSeoClient $client) {}

    public function name(): string
    {
        return 'dataforseo';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured() && $this->platforms() !== [];
    }

    /** @return list<string> */
    public function platforms(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = config('visibility.platforms', []);

        return array_values(array_filter(
            array_keys($configured),
            static fn (string $platform): bool => is_string(config("visibility.platforms.{$platform}.model"))
                && config("visibility.platforms.{$platform}.model") !== '',
        ));
    }

    public function ask(string $platform, string $prompt, ?string $countryIso = null): ?LlmAnswer
    {
        $model = config("visibility.platforms.{$platform}.model");

        if (! is_string($model) || $model === '') {
            throw new TerminalStepFailure(
                "No model is configured for the '{$platform}' assistant, so it cannot be asked. "
                .'Set one in config/visibility.php or stop listing the platform.'
            );
        }

        $task = [
            // The API caps the prompt at 500 characters. Truncating silently
            // would change the question being measured, so an over-long prompt
            // is refused at generation time; this is the belt.
            'user_prompt' => mb_substr($prompt, 0, 500),
            'model_name' => $model,
            'web_search' => true,
        ];

        // Not every assistant takes the country hint, and the ones that do not
        // reject the whole request rather than ignoring the field — so a single
        // unsupported parameter costs that assistant every answer in a sweep
        // while the others look healthy.
        if ($countryIso !== null && $countryIso !== '' && config("visibility.platforms.{$platform}.accepts_country", true)) {
            $task['web_search_country_iso_code'] = mb_strtoupper($countryIso);
        }

        $result = $this->client->post(
            "/v3/ai_optimization/{$platform}/llm_responses/live",
            $task,
            timeout: (int) config('visibility.timeout', 150),
        );

        if ($result === []) {
            return null;
        }

        $text = '';
        $citations = [];

        foreach ($this->sections($result) as $section) {
            $text .= (string) ($section['text'] ?? '');

            foreach (is_array($section['annotations'] ?? null) ? $section['annotations'] : [] as $annotation) {
                $url = $annotation['url'] ?? null;

                if (! is_string($url) || $url === '') {
                    continue;
                }

                $title = $annotation['title'] ?? null;

                $citations[] = ['url' => $url, 'title' => is_string($title) && $title !== '' ? $title : $url];
            }
        }

        if (trim($text) === '') {
            // Asked, and it said nothing usable. Not a failure — an assistant
            // declining to answer "best cleaning service in Lisbon" is itself a
            // finding, and failing the run would hide it.
            Log::info('An assistant returned no text', ['platform' => $platform, 'prompt' => $prompt]);

            return null;
        }

        return new LlmAnswer(
            platform: $platform,
            model: $model,
            text: $text,
            citations: $citations,
            moneySpent: is_numeric($result[0]['money_spent'] ?? null) ? (float) $result[0]['money_spent'] : 0.0,
        );
    }

    /**
     * An answer arrives in sections, and the citations hang off each section
     * rather than off the answer — so both have to be walked together.
     *
     * @param  list<array<string, mixed>>  $result
     * @return list<array<string, mixed>>
     */
    private function sections(array $result): array
    {
        $sections = [];

        foreach (is_array($result[0]['items'] ?? null) ? $result[0]['items'] : [] as $item) {
            foreach (is_array($item['sections'] ?? null) ? $item['sections'] : [] as $section) {
                if (is_array($section)) {
                    $sections[] = $section;
                }
            }
        }

        return $sections;
    }
}
