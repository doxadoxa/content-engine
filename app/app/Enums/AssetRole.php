<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetRole: string
{
    /** The single image at the top of the unit. At most one per unit. */
    case Hero = 'hero';

    /** Placed in the body at an anchor. */
    case Inline = 'inline';

    /**
     * A candidate picture an operator has not chosen yet.
     *
     * Its own case rather than a flag on {@see Hero}, because the database says
     * so: `assets_one_hero_per_item` is a unique index on `content_item_id`
     * where the role is `hero`, and it is right — a post ships one picture, and
     * a second row claiming to be the one it ships is a bug waiting for a
     * publisher to pick the wrong one. Variants therefore live outside that
     * index, and choosing one is a promotion: the chosen row becomes the hero
     * and the hero it replaced becomes a superseded variant. Nothing is
     * deleted, so an operator can go back to the picture they rejected.
     */
    case Variant = 'variant';

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Hero',
            self::Inline => 'Inline',
            self::Variant => 'Variant',
        };
    }
}
