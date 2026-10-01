<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OnboardingStatus;
use App\Models\Project;
use App\Rules\PublicHttpUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class OnboardingStepRequest extends FormRequest
{
    private const array STEPS = [
        'offer',
        'market',
        'business',
        'voice',
        'competitors',
        'channels',
        'settings',
    ];

    public function authorize(): bool
    {
        $project = $this->route('project');

        if ($project instanceof Project) {
            abort_unless($project->onboarding_status === OnboardingStatus::Draft, 404);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $step = (string) $this->input('step');

        return [
            'step' => ['required', Rule::in(self::STEPS)],
            'answers' => ['required', 'array:'.implode(',', $this->keysFor($step))],
            ...$this->answerRules($step),
        ];
    }

    /**
     * A money-or-health project names who stands behind its articles here,
     * because this is the last moment before the first one is written.
     *
     * A named author, or the brand publishing openly as AI-assisted. Asked
     * now rather than discovered by the engine, where it used to surface as
     * an article that stopped with no way forward in sight.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->route('project');

            // After the rules, and only if they passed: a malformed answer
            // already has its error, and reading it as a string here would
            // turn that 422 into a 500.
            if ($validator->errors()->isNotEmpty() || ! $project instanceof Project || ! $project->is_ymyl || $this->input('step') !== 'voice') {
                return;
            }

            $named = trim((string) $this->input('answers.author_name', '')) !== '';

            if (! $named && ! $this->boolean('answers.ai_disclosure')) {
                $validator->errors()->add(
                    'answers.author_name',
                    'Add the name of the person who writes for you, or publish as your brand with an AI label.',
                );
            }
        }];
    }

    /** @return list<string> */
    private function keysFor(string $step): array
    {
        return match ($step) {
            'offer' => ['key'],
            'market' => ['market', 'language', 'extra_languages', 'timezone'],
            'business' => ['name', 'description', 'audiences'],
            'voice' => [
                'tone', 'visual_language', 'forbidden', 'author_name',
                'author_title', 'sitemap_url', 'example_liked', 'example_disliked',
                'ai_disclosure',
            ],
            'competitors' => ['competitors'],
            // `sitemap_url` is accepted by both `voice` and `channels`: it was
            // asked in the voice step until the publishing step gave it a
            // better home, and a draft half-answered under the old shape must
            // still be resumable.
            'channels' => ['destination', 'webhook_endpoint', 'webhook_secret', 'sitemap_url'],
            'settings' => ['weekly_target', 'target_words', 'autopublish'],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function answerRules(string $step): array
    {
        return match ($step) {
            'offer' => ['answers.key' => ['required', 'string']],
            'market' => [
                'answers.market' => ['nullable', 'string', 'max:100'],
                'answers.language' => ['required', 'string', 'max:12', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'],
                'answers.extra_languages' => ['sometimes', 'array', 'max:10'],
                'answers.extra_languages.*' => ['string', 'max:12', 'distinct', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'],
                'answers.timezone' => ['sometimes', 'string', 'timezone'],
            ],
            'business' => [
                'answers.name' => ['required', 'string', 'max:255'],
                'answers.description' => ['required', 'string', 'max:5000'],
                'answers.audiences' => ['sometimes', 'array', 'max:20'],
                'answers.audiences.*' => ['string', 'max:255'],
            ],
            'voice' => [
                'answers.tone' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'answers.visual_language' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'answers.forbidden' => ['sometimes', 'array', 'max:50'],
                'answers.forbidden.*' => ['string', 'max:255'],
                'answers.author_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'answers.author_title' => ['sometimes', 'nullable', 'string', 'max:255'],
                'answers.ai_disclosure' => ['sometimes', 'boolean'],
                'answers.sitemap_url' => ['sometimes', 'nullable', 'url', 'max:2048', app(PublicHttpUrl::class)],
                'answers.example_liked' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'answers.example_disliked' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'competitors' => [
                'answers.competitors' => ['required', 'array', 'max:50'],
                'answers.competitors.*' => ['string', 'max:255'],
            ],
            'channels' => [
                'answers.destination' => ['sometimes', 'nullable', 'string', Rule::in(['wordpress', 'custom', 'later'])],
                'answers.sitemap_url' => ['sometimes', 'nullable', 'url', 'max:2048', app(PublicHttpUrl::class)],
                'answers.webhook_endpoint' => ['sometimes', 'nullable', 'url', 'max:2048', app(PublicHttpUrl::class)],
                'answers.webhook_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'settings' => [
                'answers.weekly_target' => ['sometimes', 'integer', 'min:1', 'max:7'],
                'answers.target_words' => ['sometimes', 'integer', 'min:400', 'max:5000'],
                'answers.autopublish' => ['sometimes', 'boolean'],
            ],
            default => [],
        };
    }
}
