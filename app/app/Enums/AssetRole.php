<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetRole: string
{
    /** The single image at the top of the unit. At most one per unit. */
    case Hero = 'hero';

    /** Placed in the body at an anchor. */
    case Inline = 'inline';

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Hero',
            self::Inline => 'Inline',
        };
    }
}
