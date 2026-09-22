<?php

declare(strict_types=1);

namespace App\Visibility\Sampling;

use App\Billing\Entitlements;
use App\Models\AiSamplingSet;
use App\Models\LlmPrompt;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\BrandPresence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SamplingSets
{
    public function __construct(private readonly CurrentProject $current) {}

    public function ensure(Project $project): ?AiSamplingSet
    {
        return $this->current->run($project, function () use ($project): ?AiSamplingSet {
            $existing = AiSamplingSet::query()->orderByDesc('version')->first();
            if ($existing !== null) {
                return $existing;
            }
            $prompts = LlmPrompt::query()->where('is_active', true)->orderBy('locale')->orderBy('id')->get()->map(static fn (LlmPrompt $prompt): array => ['text' => $prompt->text, 'locale' => $prompt->locale, 'intent' => $prompt->intent->value, 'purpose' => 'discovery'])->values()->all();
            $entitlement = app(Entitlements::class)->for($project);
            if (($entitlement->plan->version ?? 0) >= 4) {
                $prompts = array_slice(array_values(array_filter($prompts, fn (array $prompt): bool => $prompt['locale'] === $project->default_locale)), 0, $entitlement->limit('ai_questions') ?? 0);
            }
            if ($prompts === []) {
                return null;
            }

            return $this->record($project, array_values($prompts), null, null, 'Initial stable set from existing monitored questions.');
        });
    }

    /** @param array<string, mixed> $input */
    public function save(Project $project, User $actor, array $input): AiSamplingSet
    {
        abort_unless($actor->projects()->whereKey($project->id)->wherePivot('role', 'owner')->exists(), 403);
        $data = Validator::make($input, [
            'expected_set_id' => ['nullable', 'ulid'], 'reason' => ['required', 'string', 'max:1000'],
            'prompts' => ['required', 'array', 'min:1', 'max:60'],
            'prompts.*' => ['array:text,locale,intent,purpose'],
            'prompts.*.text' => ['required', 'string', 'max:500'],
            'prompts.*.locale' => ['required', 'string', 'max:35', 'regex:/^[a-zA-Z]{2,3}([_-][a-zA-Z0-9]{2,8})*$/'],
            'prompts.*.intent' => ['required', Rule::in(['buying', 'comparison', 'learning'])],
            'prompts.*.purpose' => ['required', Rule::in(['discovery', 'accuracy'])],
        ])->validate();

        return $this->current->run($project, fn (): AiSamplingSet => $this->record($project, $data['prompts'], $actor, $data['expected_set_id'] ?? null, $data['reason']));
    }

    /** A package change makes a new comparison group; historical instruments remain untouched. */
    public function forCycle(Project $project, bool $generationFinished = false): ?AiSamplingSet
    {
        if (! $generationFinished && ! AiSamplingSet::query()->exists() && $this->questionsNeeded($project) > 0) {
            return null;
        }
        $set = $this->ensure($project);
        if ($set === null) {
            return null;
        }
        $limit = app(Entitlements::class)->for($project)->limit('ai_questions') ?? 0;
        $prompts = $set->configuration['prompts'];
        $priorLimit = $set->configuration['packaged_question_limit'] ?? count($prompts);
        if (count($prompts) <= $limit && $priorLimit >= $limit) {
            return $set;
        }
        $selected = array_slice($this->expandedPrompts($project, $prompts), 0, $limit);
        if ($priorLimit < $limit && count($selected) < $limit && ! $generationFinished) {
            return null;
        }

        return $this->record($project, $selected, null, $set->id,
            count($prompts) > $limit
                ? "Plan allowance changed: retained the first {$limit} saved questions. Earlier questions and results remain in history; the owner can choose a different set."
                : 'Plan allowance increased: retained saved questions and added available customer questions. This starts a new comparison group; review the saved questions.', true);
    }

    public function questionsNeeded(Project $project): int
    {
        $set = AiSamplingSet::query()->latest('version')->first();
        $limit = app(Entitlements::class)->for($project)->limit('ai_questions') ?? 0;
        if ($set !== null && ($set->configuration['packaged_question_limit'] ?? count($set->configuration['prompts'])) >= $limit) {
            return 0;
        }

        return max(0, $limit - count($this->expandedPrompts($project, $set?->configuration['prompts'] ?? [])));
    }

    /** @param list<array<string,mixed>> $prompts
     * @return list<array<string,mixed>>
     */
    private function expandedPrompts(Project $project, array $prompts): array
    {
        $all = $prompts;
        foreach (LlmPrompt::query()->where('is_active', true)->where('locale', $project->default_locale)->orderBy('id')->get() as $prompt) {
            if (! BrandPresence::namesBrand($prompt->text, $project->name)) {
                $all[] = ['text' => $prompt->text, 'locale' => $prompt->locale, 'intent' => $prompt->intent->value, 'purpose' => 'discovery'];
            }
        }
        $unique = [];
        foreach ($all as $prompt) {
            $key = $prompt['text'].'|'.$prompt['locale'];
            $unique[$key] ??= array_intersect_key($prompt, array_flip(['text', 'locale', 'intent', 'purpose']));
        }

        return array_values($unique);
    }

    /** @param list<array<string, mixed>> $prompts */
    private function record(Project $project, array $prompts, ?User $actor, ?string $expected, string $reason, bool $automaticChange = false): AiSamplingSet
    {
        return DB::transaction(function () use ($project, $prompts, $actor, $expected, $reason, $automaticChange): AiSamplingSet {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $previous = AiSamplingSet::query()->orderByDesc('version')->first();
            if ($actor === null && $previous !== null && ! $automaticChange) {
                return $previous;
            }
            if (($actor !== null || $automaticChange) && $previous?->id !== $expected) {
                throw ValidationException::withMessages(['expected_set_id' => 'The monitored questions changed. Reload before saving a new version.']);
            }
            $seen = [];
            foreach ($prompts as &$prompt) {
                $prompt['text'] = trim($prompt['text']);
                $prompt['locale'] = str_replace('_', '-', $prompt['locale']);
                if ($prompt['purpose'] === 'discovery' && BrandPresence::namesBrand($prompt['text'], $project->name)) {
                    throw ValidationException::withMessages(['prompts' => 'Questions naming this business belong in factual accuracy, not discovery visibility.']);
                }
                $prompt['key'] = hash('sha256', json_encode([$prompt['text'], $prompt['locale'], $prompt['purpose']], JSON_THROW_ON_ERROR));
                if (isset($seen[$prompt['key']])) {
                    throw ValidationException::withMessages(['prompts' => 'Each question, language and purpose must be unique.']);
                }
                $seen[$prompt['key']] = true;
            }
            unset($prompt);
            $panel = [];
            foreach (config('visibility.platforms', []) as $platform => $settings) {
                if (! is_string($settings['model'] ?? null) || $settings['model'] === '') {
                    continue;
                }
                $panel[] = ['platform' => $platform, 'model' => $settings['model'], 'accepts_country' => $settings['accepts_country'] ?? true,
                    'web_search' => true, 'temperature' => $settings['temperature'] ?? null, 'max_output_tokens' => $settings['max_output_tokens'] ?? null];
            }
            $configuration = ['brand' => $project->name, 'website' => $project->website_url, 'market' => $project->market,
                'prompts' => $prompts, 'panel' => $panel, 'max_answers' => max(1, (int) config('visibility.max_answers_per_run', 80)),
                'measurement' => 'Provider API samples with web search requested; not personalised consumer application activity.'];
            if (app(AnswerAllowance::class)->applies($project)) {
                $configuration['packaged_question_limit'] = app(Entitlements::class)->for($project)->limit('ai_questions');
                $configuration['max_answers'] = count($prompts) * count($panel);
            }
            app(SamplingSchedule::class)->validate($project, $configuration);

            return AiSamplingSet::query()->create(['version' => ($previous->version ?? 0) + 1, 'configuration' => $configuration,
                'configuration_hash' => hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)), 'created_by' => $actor?->id, 'change_reason' => $reason]);
        });
    }
}
