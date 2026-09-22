<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

final class PlanPrice
{
    /** @param array<string, mixed> $price */
    public static function verify(Plan $plan, array $price): void
    {
        if (($price['id'] ?? null) !== $plan->stripePrice || ($price['active'] ?? null) !== true
            || ($price['currency'] ?? null) !== $plan->currency || ($price['unit_amount'] ?? null) !== $plan->priceCents
            || ($price['type'] ?? null) !== 'recurring' || ($price['recurring']['interval'] ?? null) !== 'month'
            || ($price['recurring']['interval_count'] ?? null) !== 1) {
            throw new RuntimeException('The configured checkout price does not match the displayed monthly plan, amount and currency.');
        }
    }
}
