<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentPlanStatus: string
{
    /** Being assembled. Its units may change. */
    case Draft = 'draft';

    /** Signed off. The daily workers may pick its units up. */
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
        };
    }
}
