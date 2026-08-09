<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use App\Publishing\Jobs\DeliverWebhookJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A delivery nobody is going to attempt, recognised by its age (§9).
 *
 * `pending` means "queued, not yet attempted". Every other status is written by
 * a worker that got as far as saying something, so `pending` is the only one
 * that can outlive the process meant to change it — and it does. The path is
 * short and there is no automatic way out of it:
 *
 *   - {@see DeliverWebhookJob} has `$tries = 1`, because §6.2's ladder is
 *     published in the contract and a queue retrying underneath it would
 *     produce attempts nobody promised. A SIGKILLed worker therefore does not
 *     get a second run: the reserved job comes back after the connection's
 *     `retry_after` and is failed for exceeding its one try.
 *   - Only a `retrying` row is re-dispatched, and the row never reached
 *     `retrying` — nothing recorded a failure, because nothing recorded
 *     anything.
 *   - `queue()`'s `firstOrCreate` on `dispatch_key` finds the existing row and
 *     dispatches nothing, so pressing publish again does not re-queue it.
 *
 * So the row sits at `pending` forever, the post never goes out, and §7's
 * screen says nothing is wrong. This class is the definition of "forever" — one
 * threshold, read by the sweeper that recovers such a row and by the delivery
 * log that flags it, so the screen and the command cannot disagree about which
 * rows are stuck.
 */
final class StrandedDeliveries
{
    /**
     * How long a `pending` row is given before it is presumed abandoned, in
     * seconds.
     *
     * Long enough that nothing healthy is ever swept, which means it has to
     * clear the two waits that legitimately keep a row at `pending`:
     *
     *   - the queue's own `retry_after`, 1 200 s on the Redis connection this
     *     engine runs. Until it elapses the job may still be reserved by a
     *     worker that is merely slow, and a sweep before then would dispatch a
     *     second copy of a delivery that is still running — which for Threads
     *     is how a root post gets published twice;
     *   - the publisher's lease, 480 s for the three-segment maximum
     *     ({@see ThreadsPublisher::leaseSeconds()}: eight calls at a 30-second
     *     client timeout, doubled for margin). A worker inside its lease is
     *     working.
     *
     * The two do not overlap in the worst case — a job reserved at the last
     * moment before `retry_after` can then hold a lease — so the floor is their
     * sum, 1 680 s, and 1 800 s is that rounded up to half an hour. Half an
     * hour is also short enough to matter: §4.3 places a post inside a duty
     * window the operator is present for, and a recovery that took two hours
     * would deliver into an empty room.
     */
    public const int AFTER_SECONDS = 1_800;

    /**
     * How many times a row may be swept before it is called a dead letter.
     *
     * A sweep is a wait rather than a failed attempt — nothing was sent — so it
     * spends a deferral and not a rung of §6.2's ladder, which is the same
     * accounting {@see Concerns\RecordsDeliveryOutcome::defer()} uses for a full
     * publishing window. The bound matters for the same reason it does there: a
     * worker that is being killed every time is not a delivery to keep waking
     * up forever, it is one for a person to look at.
     */
    public const int MAX_SWEEPS = 3;

    /** The moment before which a `pending` row is presumed abandoned. */
    public static function cutoff(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->copy()->subSeconds(self::seconds());
    }

    /**
     * Every delivery that has been `pending` past the threshold.
     *
     * Scoped to whatever the caller's tenancy already is: the sweeper asks
     * across projects, the delivery log asks inside one. Aged on `created_at`
     * rather than `updated_at` because `pending` is the state a row is *born*
     * in — anything that touches it moves it to another status — so the created
     * stamp is the one that cannot be pushed forward by an unrelated write and
     * quietly hide a stranded row forever.
     *
     * @param  Builder<WebhookDelivery>  $query
     * @return Builder<WebhookDelivery>
     */
    public static function scope(Builder $query, ?Carbon $now = null): Builder
    {
        return $query
            ->where('status', DeliveryStatus::Pending->value)
            ->where('created_at', '<=', self::cutoff($now));
    }

    /** Whether this row, as it stands, is one of them. */
    public static function includes(WebhookDelivery $delivery, ?Carbon $now = null): bool
    {
        return $delivery->status === DeliveryStatus::Pending
            && $delivery->created_at !== null
            && $delivery->created_at->lessThanOrEqualTo(self::cutoff($now));
    }

    /**
     * The threshold in force, which config may raise or lower.
     *
     * Configurable because the arithmetic above depends on the queue connection
     * an installation runs: a deployment on the database connection has a
     * `retry_after` of 90 seconds and could sweep far sooner. Floored at the
     * publisher's lease so no configuration can make the sweeper dispatch over
     * a worker that is still inside one.
     */
    private static function seconds(): int
    {
        $configured = config('publishing.stranded_after', self::AFTER_SECONDS);

        return max(600, is_numeric($configured) ? (int) $configured : self::AFTER_SECONDS);
    }
}
