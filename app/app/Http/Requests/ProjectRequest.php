<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProjectStatus;
use App\Http\Requests\Concerns\ValidatesDutyHours;
use App\Http\Requests\Concerns\ValidatesFeedUrls;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ProjectRequest extends FormRequest
{
    use ValidatesDutyHours;
    use ValidatesFeedUrls;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'name' => ['required', 'string', 'max:255'],
            // The slug is in no URL any more — the session carries the tenant —
            // but it is still how a project is named in the webhook payload
            // from phase 6, so it stays unique and stays put after creation.
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('projects', 'slug')->ignore($project),
            ],
            'timezone' => ['required', 'string', 'timezone'],

            // Read in the project's own `timezone`, which is why it sits next
            // to it. Empty means never on duty, so the engine schedules
            // nothing — see App\Support\Duty\DutyHours.
            ...self::dutyHoursRules('duty_hours'),

            // The RSS whitelist of §4.1 — the third intake into the listening
            // contour, next to keyword search and webhooks.
            ...self::feedUrlRules('feed_urls'),

            'default_locale' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/'],
            'locales' => ['array'],
            'locales.*' => ['string', 'max:12', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/'],
            'status' => ['required', new Enum(ProjectStatus::class)],

            // What research starts from. Editable here because when a project
            // comes back with nothing, this is almost always why — and the
            // wizard that first wrote them only runs once.
            'research_seeds' => ['array', 'max:20'],
            'research_seeds.*' => ['string', 'max:120'],
            'market' => ['sometimes', 'required', 'string', 'max:8'],
            'weekly_target' => ['sometimes', 'required', 'integer', 'min:1', 'max:14'],
            'minimum_volume' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may contain lowercase letters, numbers and single hyphens.',
            'default_locale.regex' => 'Use a BCP 47 tag, for example en or pt-PT.',
            'locales.*.regex' => 'Use BCP 47 tags, for example en or pt-PT.',
            'research_seeds.*.max' => 'A seed is a search term, not a sentence — keep it short.',
            'feed_urls.max' => 'Twenty feeds is the ceiling — this is a whitelist of sources worth reacting to, not a crawler.',
        ];
    }

    /**
     * The default locale is always published, whatever the operator typed in
     * the additional-locales field.
     *
     * Before validation rather than after: `passedValidation()` merges into the
     * request, while `safe()` and `validated()` read the validator's own copy —
     * so a merge there never reaches the controller. Doing it here also means
     * the locale we add is validated like every other one.
     */
    protected function prepareForValidation(): void
    {
        $locales = $this->input('locales');
        $default = $this->string('default_locale')->toString();

        $this->merge([
            'locales' => array_values(array_unique([
                $default,
                ...(is_array($locales) ? $locales : []),
            ])),
        ]);

        // The feed field is a textarea, so trailing newlines and a stray space
        // after a pasted URL are the normal case rather than a client that has
        // gone wrong. Tidied here, before the rules run, so the operator gets
        // "that address is not reachable" and never "the 4th feed is required".
        if ($this->has('feed_urls') && is_array($this->input('feed_urls'))) {
            $this->merge([
                'feed_urls' => array_values(array_unique(array_filter(
                    array_map(
                        static fn (mixed $url): string => is_string($url) ? trim($url) : '',
                        (array) $this->input('feed_urls'),
                    ),
                    static fn (string $url): bool => $url !== '',
                ))),
            ]);
        }
    }
}
