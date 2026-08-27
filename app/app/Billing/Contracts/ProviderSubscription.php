<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

use App\Enums\BillingStatus;
use Illuminate\Support\Carbon;

/**
 * What a provider says about one subscription, in this application's words.
 *
 * The mapping from Stripe's vocabulary happens on the far side of
 * {@see BillingProvider}, in one place, so a status Stripe adds next year lands
 * somewhere deliberate rather than arriving here as an unrecognised string that
 * reads as entitled.
 */
final readonly class ProviderSubscription
{
    public function __construct(
        public string $id,
        public BillingStatus $status,
        public ?string $priceId,
        public ?Carbon $periodStart,
        public ?Carbon $periodEnd,
        public ?Carbon $trialEnd,
        public ?Carbon $canceledAt,
    ) {}
}
