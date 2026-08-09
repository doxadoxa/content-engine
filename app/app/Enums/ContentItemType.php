<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of thing the unit is (§3.2 — typing happens in planning).
 *
 * The type drives which pipeline runs and which schema.org type the GEO layer
 * emits, which is why it is an enum and not a free-text tag.
 */
enum ContentItemType: string
{
    case HowTo = 'how_to';

    case Explainer = 'explainer';

    case Product = 'product';

    case Comparison = 'comparison';

    /** "Best X", "7 ways to Y" — a list is its own shape, not an explainer. */
    case Listicle = 'listicle';

    /**
     * A post on a social channel (§3).
     *
     * Adding it removes a hack rather than adding a feature: the repurpose
     * pipeline had no type for what it was writing, so every derivative was
     * saved as an `Explainer`. That made "how many explainers has this project
     * published" unanswerable and, worse, gave a 300-character post the
     * schema.org type of an article.
     *
     * A social unit is also the one type that may exist without a parent (§1.3
     * — "родитель необязателен"), which is why the type has to be nameable
     * independently of the tree it may or may not hang from.
     */
    case SocialPost = 'social_post';

    public function label(): string
    {
        return match ($this) {
            self::HowTo => 'How-to',
            self::Explainer => 'Explainer',
            self::Product => 'Product',
            self::Comparison => 'Comparison',
            self::Listicle => 'Listicle',
            self::SocialPost => 'Social post',
        };
    }

    /**
     * The schema.org type the GEO layer emits for this unit. Consumed in phase
     * 5; kept next to the case so the two cannot drift apart.
     */
    public function schemaType(): string
    {
        return match ($this) {
            self::HowTo => 'HowTo',
            self::Explainer, self::Comparison => 'Article',
            self::Product => 'Product',
            // schema.org has no Listicle; ItemList is what a numbered article
            // actually is, and it is what earns the list treatment in results.
            self::Listicle => 'ItemList',
            self::SocialPost => 'SocialMediaPosting',
        };
    }
}
