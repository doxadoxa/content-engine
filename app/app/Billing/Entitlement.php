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
    /** The plan key the card-free sample runs on. */
    public const string PREVIEW_PLAN = 'preview';

    /**
     * @param  array<string, int>  $usage  counters for the current period
     */
    /**
     * @param  Plan|null  $plan  what they bought — the name and the price
     * @param  Plan|null  $allowance  what bounds them right now, which during a
     *                                free window is the trial's rather than the
     *                                purchased plan's
     * @param  array<string, int>  $usage  counters for the current period
     */
    public function __construct(
        public ?ProjectSubscription $subscription,
        public ?Plan $plan,
        public ?Plan $allowance,
        public ?BillingStatus $status,
        public array $usage,
        public int $spentMicros,
        public ?Carbon $periodEndsAt,
        public ?Carbon $trialEndsAt,
        /**
         * Whether the card-free sample has already been made.
         *
         * Computed by {@see Entitlements} from the project rather than read
         * off the subscription, because it is a fact about the launch having
         * finished and the launch is the project's business. Meaningless — and
         * always false — for anything that is not a preview.
         */
        public bool $previewFinished = false,
    ) {}

    /** A project nobody has ever assigned a plan to. */
    public static function none(): self
    {
        return new self(null, null, null, null, [], 0, null, null);
    }

    /**
     * Is this the card-free sample rather than a subscription?
     *
     * Asked of the plan, which is where the bounds are. A preview is a
     * perfectly ordinary subscription to every gate in this subsystem; what is
     * different about it is one article, two and a half dollars, and that
     * nothing it makes may be published.
     */
    public function isPreview(): bool
    {
        return $this->plan?->key === self::PREVIEW_PLAN;
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
        if ($this->status === null) {
            return false;
        }

        // A sample is read, never sent.
        //
        // The preview runs as an Active subscription so that everything which
        // asks whether work may *start* gets an ordinary yes — and an Active
        // subscription is otherwise entitled to publish. Somebody who
        // connected WordPress in the wizard and left the default publishing
        // preference alone would have had the article they were being shown
        // put on their website before they were asked for a card.
        if ($this->isPreview()) {
            return false;
        }

        // Read from the dates rather than from the column, and from *both* sets
        // of dates. `billing:sweep` is what makes the record say a window ran
        // out; if delivery waited for that, a stopped scheduler would keep
        // publishing for somebody whose trial or dunning ended a week ago —
        // precisely the failure the sweep's own docblock warns about.
        //
        // The first version of this closed the hole for dunning and left it
        // open for trials, which is the larger of the two: every project starts
        // on a trial and only some ever reach dunning.
        if ($this->subscription?->graceHasExpired() === true
            || $this->subscription?->trialHasExpired() === true) {
            return false;
        }

        return $this->status->mayPublish();
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
        if ($this->subscription === null || $this->allowance === null || $this->status === null) {
            return Refusal::noSubscription();
        }

        if ($this->status === BillingStatus::Canceled) {
            return Refusal::canceled();
        }

        if ($this->status === BillingStatus::PastDue) {
            // The same sentence either way. Somebody whose grace has also run
            // out does not need a second, sterner message about the card that
            // is already the problem — what changes at that point is that
            // publishing stops too, and that is {@see mayPublish()}'s business.
            return Refusal::pastDue();
        }

        if ($this->status === BillingStatus::Trialing && $this->subscription->trialHasExpired()) {
            return Refusal::trialEnded();
        }

        // The sample spends once.
        //
        // Before the quota and before the ceiling, because neither can say
        // this. The unit counters are recorded at *approval* and a sample is
        // never approved, so an article count would stay at nought while the
        // engine happily wrote a second one; and the money ceiling is a fuse
        // rather than a rule, which would stop the engine eventually and in
        // the middle of something. What actually bounds a preview is that its
        // launch finished, which is a fact with a timestamp on it.
        if ($this->previewFinished) {
            return Refusal::previewFinished();
        }

        // Before the quota, and deliberately. A project that has burnt three
        // times its plan's cost while still inside its article count has a
        // problem the article count cannot describe, and stopping it with
        // "you have used your articles" would send somebody to buy more of
        // exactly the thing that is going wrong.
        $ceiling = $this->allowance->limit('cost_micros');

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
        $limit = $this->allowance?->limit($metric->value);

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
        $ceiling = $this->allowance?->weeklyTarget();

        return $ceiling === null ? $stored : min($stored, $ceiling);
    }

    /** Null is unlimited. */
    public function limit(string $key): ?int
    {
        return $this->allowance?->limit($key);
    }

    /**
     * The quotas with nothing left in them.
     *
     * @return list<string>
     */
    public function exhausted(): array
    {
        $out = [];

        foreach (Metric::cases() as $metric) {
            // A feature the plan excludes is not an allowance the manager
            // used up. Keep its underlying entitlement unchanged.
            if ($this->allowance?->limit($metric->value) === 0) {
                continue;
            }
            if ($this->remaining($metric) === 0) {
                $out[] = $metric->value;
            }
        }

        return $out;
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
                'preview' => false,
                'preview_finished' => false,
                'plan' => null,
                'status' => null,
                'may_generate' => false,
                'refusal' => Refusal::noSubscription()->toArray(),
                'usage' => [],
                'exhausted' => [],
                'trial_ends_at' => null,
                'period_ends_at' => null,
            ];
        }

        $usage = [];

        foreach (Metric::cases() as $metric) {
            $usage[$metric->value] = [
                'used' => $this->used($metric),
                'limit' => $this->allowance?->limit($metric->value),
                'remaining' => $this->remaining($metric),
            ];
        }

        return [
            // Named rather than inferred from the plan key on the client,
            // which would put the rule in two places and in the wrong one.
            'preview' => $this->isPreview(),
            'preview_finished' => $this->previewFinished,
            'plan' => [
                'key' => $this->plan->key,
                'name' => $this->plan->name,
                'price_cents' => $this->plan->priceCents,
                'currency' => $this->plan->currency,
            ],
            'status' => $this->status->value,
            'may_generate' => $this->mayGenerate(),
            'refusal' => $this->refusal()?->toArray(),
            'usage' => $usage,
            // Which quotas are used up, as a list rather than folded into
            // `refusal`.
            //
            // A quota is not a global refusal and must not be reported as one:
            // a project out of articles can still audit its site, so
            // `may_generate` stays true and the engine keeps working. But the
            // page had no way to say so either — `refusal()` skips the quota
            // branch when it is asked without a metric, which is how it is
            // asked here — and the result was that running out of articles was
            // invisible everywhere except a usage bar on a screen nobody had a
            // reason to open.
            'exhausted' => $this->exhausted(),
            'trial_ends_at' => $this->trialEndsAt?->toIso8601String(),
            'period_ends_at' => $this->periodEndsAt?->toIso8601String(),
        ];
    }
}
