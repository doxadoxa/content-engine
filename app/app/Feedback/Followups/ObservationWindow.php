<?php

declare(strict_types=1);

namespace App\Feedback\Followups;

use Carbon\CarbonImmutable;

final readonly class ObservationWindow
{
    public const ZONE = 'America/Los_Angeles';

    public const SETTLEMENT_DAYS = 3;

    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to, public int $days) {}

    public static function baseline(CarbonImmutable $approvedAt, int $days): self
    {
        $to = $approvedAt->setTimezone(self::ZONE)->startOfDay()->subDays(self::SETTLEMENT_DAYS);

        return new self($to->subDays($days - 1), $to, $days);
    }

    public static function afterPublication(CarbonImmutable $verifiedAt, int $days): self
    {
        // The verification date mixes old and new content. Begin the next full day.
        $from = $verifiedAt->setTimezone(self::ZONE)->startOfDay()->addDay();

        return new self($from, $from->addDays($days - 1), $days);
    }

    public function dueOn(): CarbonImmutable
    {
        return $this->to->addDays(self::SETTLEMENT_DAYS);
    }

    public function settledDays(?CarbonImmutable $now = null): int
    {
        $settledThrough = ($now ?? CarbonImmutable::now())->setTimezone(self::ZONE)->startOfDay()->subDays(self::SETTLEMENT_DAYS);

        return min($this->days, max(0, (int) $this->from->diffInDays($settledThrough) + 1));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'days' => $this->days, 'timezone' => self::ZONE];
    }
}
