<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who makes a project's content in a given language.
 *
 * Distinct from *which* languages a project publishes in: the visibility sweep
 * measures every listed locale whether or not we wrote the page, because a
 * brand is findable or invisible in a language regardless of who typed it.
 *
 * The default is {@see self::Adapt}, and that is an argument rather than an
 * accident. Every rule in the house style — the em-dash budget, the uneven
 * sentence rhythm, the fragments, the section that names real limitations — is
 * a property of prose as written. A machine translation of an article that
 * scored 100 is an article that has never been scored, and {@see
 * \App\Content\ArticleScore} cannot see it to say so. Letting the engine write
 * each language is the only arrangement in which the quality bar means anything
 * outside the source language.
 *
 * {@see self::Translate} stays because it is honestly cheaper and some
 * receivers already do it well — Cleaning Point takes one article and fills the
 * other locales itself — and because for a market whose keyword data exists in
 * one language only, the other locales are not search channels at all.
 */
enum LocaleMode: string
{
    /**
     * Planned from keyword data and written natively.
     *
     * Follows the keyword rather than a setting: a keyword lives in a language,
     * and an article in another language cannot rank for it.
     */
    case Source = 'source';

    /**
     * The engine writes this locale too, for its own audience.
     *
     * An adaptation, not a translation: same topic, own opening, own examples,
     * own title. The distinction is the house style's own — its checklist ends
     * with "the angle could not be transplanted unchanged", and a translation
     * is a transplanted angle by definition.
     */
    case Adapt = 'adapt';

    /**
     * The engine writes nothing here; the receiver makes this language.
     *
     * The payload still carries `locale_group_id`, which is what a receiver
     * needs to bind its translations to the original as hreflang alternates.
     */
    case Translate = 'translate';

    public function label(): string
    {
        return match ($this) {
            self::Source => 'Written from keyword research',
            self::Adapt => 'Written for this audience',
            self::Translate => 'Translated by the receiving site',
        };
    }

    /** Whether the engine plans and writes an article for this locale. */
    public function isWritten(): bool
    {
        return $this !== self::Translate;
    }
}
