<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\BillingStatus;
use App\Models\ProjectSubscription;
use Illuminate\Support\Carbon;

/**
 * What one project may do right now, answered once and read many times.
 *
 * Assembled by {@see Entitlements} and immutable afterwards, which is the
 * property that matters: the tick, the middleware, the shared props and the
 * paywall all consult this within one request, and an object that could
 * re-query between two of those calls is an object that can say yes to a
 * middleware and no to the screen it renders.
 *
 * The two layers of limit both live here and answer differently. A **unit
 * quota** is what the customer agreed to and is named in refusals. The **cost
 * ceiling** is the fuse: invisible, roughly three times measured cost of goods,
 * and there for the retry storm rather than for the customer.
 */
final readonly class Entitlement
{
    /**
     * @param  array<string, int>  $usage  counters for the current period
     */
    public function __construct(
        public ?ProjectSubscription $subscription,
        public ?Plan $plan,
        public ?BillingStatus $status,
        public array $usage,
        public int $spentMicros,
        public ?Carbon $periodEndsAt,
        public ?Carbon $trialEndsAt,
    ) {}

    /** A project nobody has ever assigned a plan to. */
    public static function none(): self
    {
        return new self(null, null, null, [], 0, null, null);
    }

    /**
     * May the engine spend money for this project?
     *
     * The single question this whole subsystem exists to answer. Everything
     * gated — the tick, the studio's buttons, the assistant — asks exactly
     * this, so there is one place a mistake can be and one place to fix it.
     */
    public function mayGenerate(): bool
    {
        return $this->refusal() === null;
    }

    /**
     * May approved content still be delivered?
     *
     * True through a failed payment and false only once the subscription is
     * over, which is the asymmetry the whole dunning policy rests on: we stop
     * spending our money immediately and stop delivering theirs at the end.
     * Never gated on quota — a quota bounds what is *made*, and an article that
     * was made and approved was already paid for.
     */
    public function mayPublish(): bool
    {
        return $this->status?->mayPublish() ?? false;
    }

    /**
     * Why not, or null.
     *
     * Ordered by how the answer should be *acted* on rather than by how the
     * checks happen to read. A cancelled subscription is not "out of quota"
     * even when it is also out of quota, and telling somebody whose card
     * bounced to upgrade would be the wrong button under the right sentence.
     */
    public function refusal(?Metric $metric = null, int $wanted = 1): ?Refusal
    {
        if ($this->subscription === null || $this->plan === null || $this->status === null) {
            return Refusal::noSubscription();
        }

        if ($this->status === BillingStatus::Canceled) {
            return Refusal::canceled();
        }

        if ($this->status === BillingStatus::PastDue) {
            return Refusal::pastDue();
        }

        if ($this->status === BillingStatus::Trialing && $this->subscription->trialHasExpired()) {
            return Refusal::trialEnded();
        }

        // Before the quota, and deliberately. A project that has burnt three
        // times its plan's cost while still inside its article count has a
        // problem the article count cannot describe, and stopping it with
        // "you have used your articles" would send somebody to buy more of
        // exactly the thing that is going wrong.
        $ceiling = $this->plan->limit('cost_micros');

        if ($ceiling !== null && $this->spentMicros >= $ceiling) {
            return Refusal::costCeiling();
        }

        if ($metric !== null && ! $this->hasRoomFor($metric, $wanted)) {
            return Refusal::quota($metric);
        }

        return null;
    }

    public function hasRoomFor(Metric $metric, int $wanted = 1): bool
    {
        $remaining = $this->remaining($metric);

        return $remaining === null || $remaining >= $wanted;
    }

    /** Null is unlimited, and is never the same answer as zero. */
    public function remaining(Metric $metric): ?int
    {
        $limit = $this->plan?->limit($metric->value);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->used($metric));
    }

    public function used(Metric $metric): int
    {
        return $this->usage[$metric->value] ?? 0;
    }

    /**
     * The project's cadence, clamped to what its plan allows.
     *
     * Clamped on read and never written back, which is the point. Somebody who
     * set fourteen a week and then downgraded should get fourteen again when
     * they upgrade, not discover that the plan quietly overwrote a setting
     * they chose.
     *
     * A ceiling rather than a counter because it is a *better* limit: a
     * counter stops a project dead on the 22nd, which reads as a broken engine,
     * where a clamped cadence makes the engine pace itself so the month comes
     * out even and the limit is never felt.
     */
    public function weeklyTarget(int $stored): int
    {
        $ceiling = $this->plan?->weeklyTarget();

        return $ceiling === null ? $stored : min($stored, $ceiling);
    }

    /** Null is unlimited. */
    public function limit(string $key): ?int
    {
        return $this->plan?->limit($key);
    }

    /**
     * What the banner and the paywall render.
     *
     * The cost ceiling is not in here and must not be. It is the one limit a
     * customer was never sold, and a progress bar towards a number nobody
     * mentioned is worse than no bar at all.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->subscription === null || $this->plan === null || $this->status === null) {
            return [
                'plan' => null,
                'status' => null,
                'may_generate' => false,
                'refusal' => Refusal::noSubscription()->toArray(),
                'usage' => [],
                'trial_ends_at' => null,
                'period_ends_at' => null,
            ];
        }

        $usage = [];

        foreach (Metric::cases() as $metric) {
            $usage[$metric->value] = [
                'used' => $this->used($metric),
                'limit' => $this->plan->limit($metric->value),
                'remaining' => $this->remaining($metric),
            ];
        }

        return [
            'plan' => [
                'key' => $this->plan->key,
                'name' => $this->plan->name,
                'price_cents' => $this->plan->priceCents,
            ],
            'status' => $this->status->value,
            'may_generate' => $this->mayGenerate(),
            'refusal' => $this->refusal()?->toArray(),
            'usage' => $usage,
            'trial_ends_at' => $this->trialEndsAt?->toIso8601String(),
            'period_ends_at' => $this->periodEndsAt?->toIso8601String(),
        ];
    }
}
