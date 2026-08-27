<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryStatus: string
{
    /** Queued, not yet attempted. */
    case Pending = 'pending';

    /** The receiver took it. */
    case Delivered = 'delivered';

    /** Failed, and another attempt is scheduled. */
    case Retrying = 'retrying';

    /** Out of attempts, or refused for a reason retrying cannot fix. */
    case DeadLetter = 'dead_letter';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Delivered => 'Delivered',
            self::Retrying => 'Retrying',
            self::DeadLetter => 'Failed',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::Delivered || $this === self::DeadLetter;
    }
}
