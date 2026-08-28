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
        /**
         * Stripe's own word for it, unmapped.
         *
         * Kept beside the mapped one because `project_subscriptions.stripe_status`
         * is documented as holding what the provider said, and writing our
         * reduced vocabulary into it would destroy the only record of which of
         * `incomplete`, `unpaid` and `past_due` we were actually told.
         */
        public string $rawStatus,
        public ?string $priceId,
        public ?Carbon $periodStart,
        public ?Carbon $periodEnd,
        public ?Carbon $trialEnd,
        public ?Carbon $canceledAt,
    ) {}
}
