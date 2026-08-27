<?php

declare(strict_types=1);

namespace App\Enums;

use App\Billing\ProjectSubscriptionStatus;

/**
 * Where a project stands with us.
 *
 * Fewer states than Stripe has, on purpose. Stripe distinguishes `incomplete`
 * from `incomplete_expired` from `unpaid` because it is describing the life of
 * an invoice; this application only ever asks one question of a subscription —
 * may this project spend money — and every one of those three answers it the
 * same way. A state nothing branches on is a state that will eventually be
 * branched on wrongly.
 *
 * {@see ProjectSubscriptionStatus} maps the provider's vocabulary
 * onto this one, in one place, so a status Stripe adds next year lands
 * somewhere deliberate rather than as an unrecognised string that reads as
 * entitled.
 */
enum BillingStatus: string
{
    /** Paying for itself. */
    case Active = 'active';

    /** Inside the free window, which ends on a date and not on a payment. */
    case Trialing = 'trialing';

    /**
     * A payment failed and the grace has not run out. Generation stops here
     * and publishing does not — approved work a customer already paid for must
     * go out whatever their card is doing.
     */
    case PastDue = 'past_due';

    /**
     * Cancelled, or a trial that ended without one. Nothing new is made; every
     * article, brief, metric and audit stays readable for ever.
     */
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Trialing => 'Trial',
            self::PastDue => 'Payment failed',
            self::Canceled => 'Cancelled',
        };
    }

    /**
     * Whether the engine may spend money for this project.
     *
     * `past_due` is false here and deliberately so: the grace it is inside
     * protects *publishing*, which is a different question and is asked
     * separately. Spending more of our money on a customer whose last payment
     * bounced is the one thing dunning exists to stop.
     */
    public function mayGenerate(): bool
    {
        return $this === self::Active || $this === self::Trialing;
    }

    /**
     * Whether approved content may still be delivered.
     *
     * True through a failed payment, false once the subscription is over. The
     * asymmetry with {@see mayGenerate()} is the whole of the dunning policy:
     * we stop spending immediately and stop delivering only at the end.
     */
    public function mayPublish(): bool
    {
        return $this !== self::Canceled;
    }
}
