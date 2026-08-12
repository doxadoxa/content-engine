<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which of the three sub-scores a check feeds.
 *
 * The reference product reports one number and three beside it, and the three
 * are what make the one actionable: a site at 74 because it is slow needs a
 * different afternoon from a site at 74 because nothing is marked up. A check
 * belongs to exactly one group, so no finding is counted twice.
 *
 * `Geo` rather than `Llm`, matching the vocabulary the rest of this codebase
 * already uses for the same idea (§9.3, `BuildGeoLayer`). The screen still
 * calls it "LLM optimization", because that is what an operator recognises.
 */
enum AuditCheckGroup: string
{
    /** Found and ranked by a search engine: titles, canonicals, links. */
    case Seo = 'seo';

    /** Quotable by an assistant: llms.txt, structured data, headings. */
    case Geo = 'geo';

    /** Fast enough that none of the above gets a chance to matter. */
    case Performance = 'performance';

    public function label(): string
    {
        return match ($this) {
            self::Seo => 'SEO optimization',
            self::Geo => 'LLM optimization',
            self::Performance => 'Page speed',
        };
    }

    /**
     * How much of the headline score this group carries.
     *
     * SEO is weighted heaviest because it has the most checks behind it and the
     * most direct consequence. Performance is lightest, and deliberately so: it
     * is the group most likely to be unmeasured — a deployment with no
     * PageSpeed key never scores it — and a heavy weight on an absent number
     * makes the headline lurch when a key is added.
     */
    public function weight(): float
    {
        return match ($this) {
            self::Seo => 0.45,
            self::Geo => 0.35,
            self::Performance => 0.20,
        };
    }
}
