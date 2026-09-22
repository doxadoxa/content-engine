<?php

declare(strict_types=1);

namespace App\Visibility;

use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Research\DataForSeo\DataForSeoClient;
use App\Visibility\Contracts\LlmVisibilityGateway;

/** Provider API samples, not reproductions of personalised consumer applications. */
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
        return array_values(array_filter(array_map(strval(...), array_keys(config('visibility.platforms', []))), static fn (string $platform): bool => is_string(config("visibility.platforms.{$platform}.model")) && config("visibility.platforms.{$platform}.model") !== ''));
    }

    /** @return list<array<string, mixed>> */
    public function models(string $platform): array
    {
        $this->validatePlatform($platform);

        return $this->client->get("/v3/ai_optimization/{$platform}/llm_responses/models");
    }

    /** @param array<string, mixed> $settings */
    public function ask(string $platform, string $prompt, ?string $countryIso = null, array $settings = []): ?LlmAnswer
    {
        $this->validatePlatform($platform);
        $model = $settings['model'] ?? config("visibility.platforms.{$platform}.model");
        if (! is_string($model) || $model === '' || trim($prompt) === '' || mb_strlen($prompt) > 500) {
            throw new TerminalStepFailure('The exact prompt and a configured model are required; prompts cannot exceed 500 characters.');
        }
        $task = ['user_prompt' => $prompt, 'model_name' => $model, 'web_search' => true];
        $acceptsCountry = $settings['accepts_country'] ?? config("visibility.platforms.{$platform}.accepts_country", true);
        if ($countryIso !== null && $countryIso !== '' && $acceptsCountry === true) {
            $task['web_search_country_iso_code'] = mb_strtoupper($countryIso);
        }
        if (isset($settings['tag'])) {
            $task['tag'] = $settings['tag'];
        }
        // Parameters are pinned by the sampling set; omission uses the provider default, recorded as such.
        foreach (['temperature', 'max_output_tokens'] as $key) {
            if (isset($settings[$key])) {
                $task[$key] = $settings[$key];
            }
        }
        $envelope = $this->client->postTask("/v3/ai_optimization/{$platform}/llm_responses/live", $task, timeout: (int) config('visibility.timeout', 150));
        $result = $envelope->results[0] ?? [];
        $sections = [];
        $citations = [];
        foreach (is_array($result['items'] ?? null) ? $result['items'] : [] as $item) {
            // Legacy providers omitted type; explicit reasoning/tool items must never become answer text.
            if (! is_array($item) || ! in_array($item['type'] ?? 'message', ['message'], true)) {
                continue;
            }
            foreach (is_array($item['sections'] ?? null) ? $item['sections'] : [] as $section) {
                if (! is_array($section) || ! in_array($section['type'] ?? 'text', ['text', 'output_text'], true)) {
                    continue;
                }
                $text = is_string($section['text'] ?? null) ? $section['text'] : '';
                $annotations = [];
                foreach (is_array($section['annotations'] ?? null) ? $section['annotations'] : [] as $annotation) {
                    if (! is_array($annotation) || ! is_string($annotation['url'] ?? null) || ! in_array(strtolower((string) parse_url($annotation['url'], PHP_URL_SCHEME)), ['https', 'http'], true)) {
                        continue;
                    }
                    $citation = ['url' => $annotation['url'], 'title' => is_string($annotation['title'] ?? null) ? $annotation['title'] : $annotation['url']];
                    $annotations[] = [...$citation, 'start_index' => $annotation['start_index'] ?? null, 'end_index' => $annotation['end_index'] ?? null, 'text' => $annotation['text'] ?? null];
                    $citations[] = $citation;
                }
                $sections[] = ['text' => $text, 'annotations' => $annotations];
            }
        }
        $text = implode("\n\n", array_column($sections, 'text'));
        if (trim($text) === '' && ! ($settings['preserve_empty'] ?? false)) {
            return null;
        }

        // Even an empty returned answer retains task identity and cost in the sampling record.
        return new LlmAnswer($platform, is_string($result['model_name'] ?? null) ? $result['model_name'] : $model, $text, $citations,
            is_numeric($result['money_spent'] ?? null) ? (float) $result['money_spent'] : 0.0,
            $sections, [
                'task_id' => $envelope->id, 'requested_model' => $model, 'resolved_model' => $result['model_name'] ?? null,
                'provider_datetime' => $result['datetime'] ?? null, 'requested_country' => $countryIso,
                'sent_country' => $task['web_search_country_iso_code'] ?? null,
                'country_control' => isset($task['web_search_country_iso_code']) ? 'sent_to_provider' : 'not_supported_or_unspecified',
                'web_search_requested' => true, 'web_search_reported' => $result['web_search'] ?? null,
                'request' => $task, 'input_tokens' => $result['input_tokens'] ?? null, 'output_tokens' => $result['output_tokens'] ?? null,
                'finish_reason' => $result['finish_reason'] ?? null, 'completion_state' => isset($result['finish_reason']) ? 'provider_reported' : 'not_reported',
            ], $envelope->cost);
    }

    private function validatePlatform(string $platform): void
    {
        if (! in_array($platform, ['chat_gpt', 'gemini', 'claude', 'perplexity'], true)) {
            throw new TerminalStepFailure('Unsupported sampling platform.');
        }
    }
}
