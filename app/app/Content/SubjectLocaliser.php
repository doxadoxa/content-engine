<?php

declare(strict_types=1);

namespace App\Content;

use App\Ai\Contracts\ModelSession;
use App\Ai\ModelRequest;
use App\ContentStudio\ContentStudioAssistant;
use App\Media\HeroImage;
use App\Models\ContentItem;
use App\Pipelines\Steps\Planning\LocaliseVariants;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The same subject, said in another language.
 *
 * A locale row is created by copying the unit it came from, so it starts life
 * holding the source language's title and search phrase. Generation reads both:
 * `write_outline` for a Russian article was prompted with `Target query:
 * limpeza pós-obra` / `Language: ru` and answered with Portuguese entities,
 * which `cover_entities` then obliged the draft to name. The published article
 * read "Espuma de poliuretano, или монтажная пена".
 *
 * A translation of the *subject*, not a search for a new one. The rows of a
 * locale group are one unit in several languages — that is what lets them share
 * a repurpose tree, a seasonal curve and the photographs
 * {@see HeroImage} lends between them — so the subject stays fixed
 * and only its expression changes. What it produces is **not** a researched
 * keyword and has no volume behind it; see
 * `product/native-keywords-per-locale.md`.
 *
 * Its own class because two callers need exactly this and a prompt written
 * twice is a prompt that drifts: {@see LocaliseVariants}
 * does it for a month as it is planned, and `planning:localise` does it for the
 * rows planned before that step existed.
 *
 * Takes the model door rather than reaching for one, matching
 * {@see ContentStudioAssistant}: inside a pipeline that door
 * is the step's own context, so the call is metered onto the step that made it.
 */
final class SubjectLocaliser
{
    /** Long enough for a headline, short enough that a paragraph is a refusal. */
    private const int MAX_TITLE = 160;

    private const int MAX_QUERY = 80;

    /**
     * One call for all of a unit's languages.
     *
     * Per unit rather than per row: the locales of a unit are one question
     * ("say this in these languages"), the output stays bounded, and a unit
     * whose answer comes back unusable costs its own titles rather than the
     * month's.
     *
     * Strict about the shape and forgiving about the failure. Anything that
     * cannot be read comes back missing and the caller leaves that row alone —
     * a plan with an untranslated card is worth more than no plan, and the card
     * says so on the calendar.
     *
     * @param  list<string>  $locales
     * @return array<string, array{title: string, query: string}> keyed by the locale asked for
     */
    public function for(ModelSession $models, ContentItem $source, array $locales): array
    {
        if ($locales === []) {
            return [];
        }

        $answer = $models->send(new ModelRequest(
            'utility',
            'You localise article subjects. You write the target language natively '
            .'and never leave source-language words in the output.',
            implode("\n\n", array_filter([
                "Subject, in {$source->locale}: {$source->title}",
                $source->target_query === null ? null : "Search phrase it targets: {$source->target_query}",
                'Languages wanted: '.implode(', ', $locales),
                'For each language, give the same subject as a natural article title a reader of '
                .'that language would click, and the short phrase they would type into a search '
                .'engine to find it. Both must read as originally written in that language, not '
                .'as a translation: keep place names and brand names, and do not carry over words '
                .'from the source language for things that language has its own word for.',
                // Pipes rather than tabs, matching the `ENTITIES:` line in
                // {@see \App\Pipelines\Steps\Generation\WriteOutline}: a tab is
                // whitespace to a model and comes back as spaces often enough
                // to matter, and a title has no reason to contain a pipe.
                'Return one line per language, exactly `<language> | <title> | <search phrase>`, '
                .'and nothing else. No numbering, no quotes, no commentary.',
            ])),
        ));

        $said = [];

        foreach (preg_split('/\R/u', trim($answer->text)) ?: [] as $line) {
            // Limit 3, so a title carrying a pipe loses the pipe rather than
            // the whole line.
            $parts = array_map(trim(...), explode('|', trim($line), 3));

            if (count($parts) < 3) {
                continue;
            }

            [$locale, $title, $query] = $parts;

            // The model is asked for the locales it was given and sometimes
            // answers with a language name. Matched loosely on the way in
            // rather than trusted, so `ru`, `ru-RU` and `Russian` all land on
            // the row that asked.
            $matched = $this->matchLocale($locale, $locales);

            if ($matched === null || $title === '' || $query === '') {
                continue;
            }

            // A paragraph where a title was asked for is a refusal wearing the
            // right shape.
            if (mb_strlen($title) > self::MAX_TITLE || mb_strlen($query) > self::MAX_QUERY) {
                continue;
            }

            $said[$matched] = ['title' => $title, 'query' => $query];
        }

        if (count($said) < count($locales)) {
            Log::warning('A unit was not localised into every language its project writes', [
                'unit' => $source->slug,
                'wanted' => $locales,
                'got' => array_keys($said),
            ]);
        }

        return $said;
    }

    /**
     * A slug for the title a row has just been given.
     *
     * The old one was built from the source's, so it read as Portuguese on a
     * Russian page. Safe to replace here and nowhere later: this runs while the
     * unit is still an `idea`, so the slug is not yet an address anybody could
     * have followed.
     *
     * The language suffix stays. `(project, slug, locale)` is unique, and two
     * locales that slug identically still collide without it.
     */
    public function slugFor(ContentItem $unit, string $title): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            return $unit->slug;
        }

        $slug = $base.'-'.Str::lower(Str::before($unit->locale, '-'));

        $taken = ContentItem::query()
            ->where('slug', $slug)
            ->where('locale', $unit->locale)
            ->whereKeyNot($unit->getKey())
            ->exists();

        return $taken ? $slug.'-'.Str::lower(Str::random(4)) : $slug;
    }

    /**
     * @param  list<string>  $locales
     */
    private function matchLocale(string $said, array $locales): ?string
    {
        $said = mb_strtolower(trim($said, " \t:-"));

        foreach ($locales as $locale) {
            $lower = mb_strtolower($locale);

            if ($said === $lower || str_starts_with($said, Str::before($lower, '-'))) {
                return $locale;
            }
        }

        return null;
    }
}
