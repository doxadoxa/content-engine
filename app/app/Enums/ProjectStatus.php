<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectStatus: string
{
    /** Pipelines run on schedule. */
    case Active = 'active';

    /** Scheduled pipelines skip this project; existing data stays readable. */
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
        };
    }
}
