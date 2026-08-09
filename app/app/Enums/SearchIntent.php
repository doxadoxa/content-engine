<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What somebody typing this query actually wants (§4.1).
 *
 * Intent is what clustering groups on, and it decides the unit type: a
 * "how to X" and a "best X for Y" are different articles even when the keyword
 * research returns them side by side.
 */
enum SearchIntent: string
{
    /** Wants to understand something. */
    case Informational = 'informational';

    /** Comparing options before buying. */
    case Commercial = 'commercial';

    /** Ready to act. */
    case Transactional = 'transactional';

    /** Looking for a specific place or brand. */
    case Navigational = 'navigational';

    public function label(): string
    {
        return match ($this) {
            self::Informational => 'Informational',
            self::Commercial => 'Commercial',
            self::Transactional => 'Transactional',
            self::Navigational => 'Navigational',
        };
    }

    /**
     * The unit type this intent usually wants.
     *
     * A default, not a rule — the planner may override it, and does for
     * anything the brief marks as a product page.
     */
    public function suggestedType(): ContentItemType
    {
        return match ($this) {
            self::Informational => ContentItemType::HowTo,
            self::Commercial => ContentItemType::Comparison,
            self::Transactional => ContentItemType::Product,
            self::Navigational => ContentItemType::Explainer,
        };
    }
}
