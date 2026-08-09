<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ChannelType;
use App\Enums\OnboardingStatus;
use App\Http\Requests\Concerns\ValidatesDutyHours;
use App\Models\Project;
use App\Rules\PublicHttpUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OnboardingStepRequest extends FormRequest
{
    use ValidatesDutyHours;

    private const array STEPS = [
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

    /** @return list<string> */
    private function keysFor(string $step): array
    {
        return match ($step) {
            'market' => ['market', 'language', 'extra_languages', 'timezone', 'duty_hours'],
            'business' => ['name', 'description', 'audiences'],
            'voice' => [
                'tone', 'visual_language', 'forbidden', 'author_name',
                'author_title', 'sitemap_url', 'example_liked', 'example_disliked',
            ],
            'competitors' => ['competitors'],
            'channels' => ['webhook_endpoint', 'webhook_secret', 'social'],
            'settings' => ['weekly_target', 'target_words', 'autopublish'],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function answerRules(string $step): array
    {
        return match ($step) {
            'market' => [
                'answers.market' => ['nullable', 'string', 'max:100'],
                'answers.language' => ['required', 'string', 'max:12', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'],
                'answers.extra_languages' => ['sometimes', 'array', 'max:10'],
                'answers.extra_languages.*' => ['string', 'max:12', 'distinct', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'],
                'answers.timezone' => ['sometimes', 'string', 'timezone'],
                ...self::dutyHoursRules('answers.duty_hours'),
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
                'answers.sitemap_url' => ['sometimes', 'nullable', 'url', 'max:2048', app(PublicHttpUrl::class)],
                'answers.example_liked' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'answers.example_disliked' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'competitors' => [
                'answers.competitors' => ['required', 'array', 'max:50'],
                'answers.competitors.*' => ['string', 'max:255'],
            ],
            'channels' => [
                'answers.webhook_endpoint' => ['sometimes', 'nullable', 'url', 'max:2048', app(PublicHttpUrl::class)],
                'answers.webhook_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
                'answers.social' => ['sometimes', 'array', 'max:10'],
                'answers.social.*' => [
                    'string', 'distinct',
                    Rule::in(array_map(
                        static fn (ChannelType $type): string => $type->value,
                        array_filter(ChannelType::cases(), static fn (ChannelType $type): bool => $type->isSocial()),
                    )),
                ],
            ],
            'settings' => [
                'answers.weekly_target' => ['required', 'integer', 'min:1', 'max:7'],
                'answers.target_words' => ['sometimes', 'integer', 'min:400', 'max:5000'],
                'answers.autopublish' => ['sometimes', 'boolean'],
            ],
            default => [],
        };
    }
}
