<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Billing\Entitlements;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class ProjectRequest extends FormRequest
{
    public const AUTOPUBLISH_REFUSED = 'Articles on this topic need a person to approve them, so they cannot publish automatically.';

    public const BYLINE_REQUIRED = 'Articles on this topic need an author. Add a name, or turn on the AI label.';

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
            'autopublish' => ['sometimes', 'boolean'],

            'default_locale' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/'],
            // Bounded by the plan, which is where the pricing table's
            // "Languages" row stops being a claim and becomes a rule. It was
            // advertised and read by nothing, so a Small subscriber could
            // publish in as many languages as they typed — including after
            // downgrading from a plan that allowed them.
            //
            // The default locale is always in this array (see
            // `prepareForValidation()`), so a limit of one means the default
            // and nothing else, which is exactly what one language is.
            'locales' => ['array', ...($this->localeLimit() === null ? [] : ['max:'.$this->localeLimit()])],
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

            // Who the articles say wrote them. Here as well as in setup, since
            // setup only runs once and a stopped article points here.
            'author_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'author_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ai_disclosure' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A YMYL project reviews every article, so it cannot ask for automatic.
     *
     * Every automatic approval on one is refused ("This topic needs a person
     * to review and approve the article"), and letting the owner choose it
     * anyway would turn the calendar into a column of blocked articles. The
     * onboarding step makes the same refusal silently; here it is said.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->route('project');

            if ($project instanceof Project && $project->is_ymyl && $this->boolean('autopublish')) {
                $validator->errors()->add('autopublish', self::AUTOPUBLISH_REFUSED);
            }

            // Removing the author and the AI label together would stop every
            // article on a money-or-health project at its first step.
            if ($project instanceof Project && $project->is_ymyl && ! $validator->errors()->hasAny(['author_name', 'ai_disclosure'])) {
                $name = $this->has('author_name')
                    ? trim((string) $this->input('author_name'))
                    : (string) ($project->namedAuthor()['name'] ?? '');
                $disclosed = $this->has('ai_disclosure') ? $this->boolean('ai_disclosure') : $project->ai_disclosure;

                if ($name === '' && ! $disclosed) {
                    $validator->errors()->add('author_name', self::BYLINE_REQUIRED);
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may contain lowercase letters, numbers and single hyphens.',
            'default_locale.regex' => 'Use a BCP 47 tag, for example en or pt-PT.',
            'locales.max' => $this->localeLimit() === 1
                ? 'This plan publishes in one language. A larger plan publishes in more.'
                : 'This plan publishes in :max languages. A larger plan publishes in more.',
            'locales.*.regex' => 'Use BCP 47 tags, for example en or pt-PT.',
            'research_seeds.*.max' => 'A seed is a search term, not a sentence — keep it short.',
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
    }

    /**
     * How many languages this project's plan allows, or null for no bound.
     *
     * Read through the current project rather than the route's, because this
     * request is only ever made about the one being worked in.
     */
    private function localeLimit(): ?int
    {
        $project = app(CurrentProject::class)->get();

        return $project instanceof Project
            ? app(Entitlements::class)->for($project)->limit('locales')
            : null;
    }
}
