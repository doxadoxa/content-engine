<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SitePage;
use App\Support\Corpus\SiteLibrary;

/**
 * What a page on the customer's own site is for.
 *
 * {@see SitePage::$is_article} already answers a two-way version of this and is
 * kept, because the question it answers — has somebody already covered this
 * topic — is a real one and a URL-path guess is good enough for it. This is the
 * other question, and it needs a third value: **where does this business state
 * something checkable about itself?**
 *
 * The distinction is the whole of the evidence problem. A planner given article
 * titles writes "Cleaning Point has an article titled X" and calls it evidence;
 * a planner given the services page writes what the service costs. On the
 * sitemap this was written against, the pages that had never been fetched
 * included `/services` and `/services/add-ons`.
 *
 * **Three values and not more.** A finer taxonomy — pricing, about, FAQ,
 * guarantees — is one a model assigns at random, and nothing downstream would
 * treat them differently anyway: they are all the business speaking about its
 * own offer, and they are all read the same way.
 */
enum SitePageKind: string
{
    /**
     * The business asserting its own offer: services, pricing, add-ons,
     * coverage, guarantees, about, FAQ. The only pages whose body is stored.
     */
    case Commercial = 'commercial';

    /**
     * Articles, journal entries, guides — anything written *about* a subject
     * rather than stating what is sold.
     *
     * Deliberately not a source of facts, for two reasons that arrive at
     * different times. An article is already an interpretation, so sourcing one
     * produces a post citing the company's own opinion piece as news. And the
     * journal will increasingly be written by this engine, at which point
     * sourcing articles means sourcing itself — the failure `config` names
     * elsewhere as "how a made-up number becomes a fact by the third
     * refinement".
     */
    case Editorial = 'editorial';

    /**
     * Contact forms, legal pages, language switchers, listing pages, the empty
     * index that redirects. Read once so it is not read again, and otherwise
     * ignored.
     */
    case Other = 'other';

    /**
     * The value a model's answer falls back to.
     *
     * `other` rather than `commercial`, because the cost is asymmetric: a
     * commercial page missed is a fact the planner does not get, and a
     * contact form admitted is a fact the planner invents from a phone number.
     * {@see SiteLibrary} re-reads nothing, so a mistake here is corrected by an
     * operator rather than by another crawl.
     */
    public static function fallback(): self
    {
        return self::Other;
    }

    public static function tryFromLoose(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(mb_strtolower(trim($value)));
    }

    /** The kinds a model may choose between, as prompt text. */
    public static function alternation(): string
    {
        return implode('|', array_map(static fn (self $kind): string => $kind->value, self::cases()));
    }
}
