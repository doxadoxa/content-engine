<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Brand\VisualStyle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One saved edit of the Brand Brief.
 *
 * The four list fields reach the server as text, one entry per line, because
 * that is how they are edited: an operator pasting five competitor domains
 * should not be operating a repeater widget. They are split here rather than in
 * the controller so what is validated is what is stored.
 */
class BrandBriefRequest extends FormRequest
{
    /** @var list<string> */
    private const array LIST_FIELDS = [
        'forbidden_topics',
        'examples_liked',
        'examples_disliked',
        'competitors',
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'positioning' => ['nullable', 'string', 'max:5000'],
            'audience' => ['nullable', 'string', 'max:5000'],
            'tone' => ['nullable', 'string', 'max:5000'],
            'visual_language' => ['nullable', 'string', 'max:5000'],

            // Validated here as well as in VisualStyle, and the two are not
            // redundant. This one tells an operator that what they typed is
            // not a colour, at the moment they typed it; that one keeps a bad
            // value that reaches the row anyway — from a seeder, the console,
            // or a row written before this rule existed — from being read.
            'brand_colour' => ['nullable', 'string', 'regex:/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'brand_ink' => ['nullable', 'string', 'regex:/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'brand_accent' => ['nullable', 'string', 'regex:/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            // The brand's other colours, beyond the three above. Eight, because that is what a site read hands back — see
            // {@see \App\Onboarding\SiteInspection::swatches()} — and a cap the
            // interface can actually show in one row.
            'brand_palette' => ['nullable', 'array', 'max:8'],
            'brand_palette.*' => ['string', 'regex:/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            // Restricted to the faces the brief offers, so the stored value is
            // always one the rest of the code recognises.
            'brand_typeface' => ['nullable', 'string', Rule::in(array_keys(VisualStyle::TYPEFACES))],
            'overlay_position' => ['nullable', 'string', 'in:top,centre,bottom'],
            'overlay_case' => ['nullable', 'string', 'in:sentence,upper'],

            'forbidden_topics' => ['array', 'max:100'],
            'forbidden_topics.*' => ['string', 'max:500'],
            'examples_liked' => ['array', 'max:100'],
            'examples_liked.*' => ['string', 'max:2000'],
            'examples_disliked' => ['array', 'max:100'],
            'examples_disliked.*' => ['string', 'max:2000'],
            'competitors' => ['array', 'max:100'],
            'competitors.*' => ['string', 'max:255'],

            // Optional, but this is the field that makes the version history
            // readable a year later, so the form asks for it every time.
            'change_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'visual_language' => 'visual language',
            'brand_colour' => 'brand colour',
            'brand_ink' => 'text colour on the brand colour',
            'brand_accent' => 'accent colour',
            'brand_palette' => 'brand palette',
            'brand_typeface' => 'typeface',
            'brand_palette.*' => 'palette colour',
            'overlay_position' => 'overlay position',
            'overlay_case' => 'overlay case',
            'forbidden_topics' => 'topics to avoid',
            'examples_liked' => 'good examples',
            'examples_disliked' => 'bad examples',
            'change_note' => 'reason for the change',
        ];
    }

    protected function prepareForValidation(): void
    {
        $split = [];

        foreach (self::LIST_FIELDS as $field) {
            $raw = $this->input($field);

            // Already an array (a future API client) passes through untouched.
            if (is_array($raw)) {
                continue;
            }

            $split[$field] = $this->lines(is_string($raw) ? $raw : '');
        }

        // An empty palette submits nothing at all, because it is a set of hidden
        // inputs and a set with no members sends no fields. Absent would then
        // reach {@see BrandBrief::revise()} as "leave this alone" and carry the
        // old palette forward — so removing every colour could never stick.
        //
        // Safe to default here and nowhere else: this request *is* the brief
        // form, which submits every field on every save, so absent genuinely
        // means empty. `revise()` called from code keeps its carry-forward, and
        // the onboarding agent depends on that.
        if (! $this->has('brand_palette')) {
            $split['brand_palette'] = [];
        }

        $this->merge($split);
    }

    /**
     * @return list<string>
     */
    private function lines(string $value): array
    {
        $lines = preg_split('/\R/u', $value) ?: [];

        return array_values(array_filter(
            array_map(trim(...), $lines),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
