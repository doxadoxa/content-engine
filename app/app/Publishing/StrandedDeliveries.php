<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use App\Publishing\Jobs\DeliverWebhookJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A delivery nobody is going to attempt, recognised by how overdue it is (§9).
 *
 * Two statuses promise that a job is on its way: `pending` ("queued, not yet
 * attempted") and `retrying` ("a job is waiting for `next_attempt_at`"). Both
 * are written *before* the job runs, so both can outlive the process meant to
 * change them — and they do. The path is short and there is no automatic way
 * out of it:
 *
 *   - {@see DeliverWebhookJob} has `$tries = 1`, because §6.2's ladder is
 *     published in the contract and a queue retrying underneath it would
 *     produce attempts nobody promised. A worker restarted with the job in
 *     hand therefore does not get a second run: the reserved job comes back
 *     after the connection's `retry_after` and is failed for exceeding its one
 *     try, without running.
 *   - A `pending` row never reached `retrying` — nothing recorded a failure,
 *     because nothing recorded anything. And `queue()`'s `firstOrCreate` on
 *     `dispatch_key` finds the existing row and dispatches nothing, so pressing
 *     publish again does not re-queue it.
 *   - A `retrying` row's only job is the delayed one `attempt()` dispatched.
 *     Lose that and the row says "next attempt at 14:05" for ever.
 *
 * So the row sits where it is, the article never goes out, and §7's screen
 * says nothing is wrong. This class is the definition of "for ever" — one
 * threshold, read by the sweeper that recovers such a row and by the delivery
 * log that flags it, so the screen and the command cannot disagree about which
 * rows are stuck.
 *
 * "Overdue" is measured from when the row was due: `next_attempt_at` when it
 * has one, `created_at` otherwise. A `retrying` row always has one; a `pending`
 * row has one only when it was replayed in place, which keeps its original
 * `created_at` and would otherwise look abandoned the moment it was queued.
 */
final class StrandedDeliveries
{
    /**
     * How long a row may be overdue before it is presumed abandoned, in
     * seconds.
     *
     * Meant to be long enough that nothing healthy is ever swept. A job being
     * run is protected by the delivery lock, which outlives the worker's
     * timeout (`publishing.lock_seconds`); a job waiting in the queue is
     * protected by the sweep refusing to run while the lane has a backlog. What
     * is left is the job Redis still holds as reserved for a worker that has
     * gone: until the connection's `retry_after` it is not yet re-offered and
     * failed, and a replacement sent before then races it. So this has to stay
     * above `retry_after` on the `publishing` connection (150 s, see
     * `config/queue.php`), and `StrandedThresholdTest` fails if the shipped
     * defaults stop doing so.
     *
     * Four minutes clears it by a minute and a half — one sweep and some
     * slack. It was an hour while deliveries shared the pipeline's connection
     * and its 2700-second `retry_after`, which is how an owner came to watch an
     * article say "in progress" for seventy minutes after a deploy. Short
     * enough now that a restart costs a scheduled article minutes rather than
     * its slot.
     */
    public const int AFTER_SECONDS = 240;

    /**
     * How many times a row may be swept before it is called a dead letter.
     *
     * A sweep is not a failed attempt — it does not walk §6.2's ladder — and
     * it is not a deferral either, which is the receiver's end asking us to
     * wait ({@see Concerns\RecordsDeliveryOutcome::defer()}). It is counted in
     * `sweeps`, its own column, so that neither bound is spent by the other.
     * The bound matters for the same reason the deferral one does: a delivery
     * whose worker keeps disappearing is not one to keep waking up forever, it
     * is one for a person to look at.
     */
    public const int MAX_SWEEPS = 3;

    /**
     * What `publish:sweep-stranded` writes on a row, kept here so the screens
     * can recognise it rather than show a queue operator's note to an owner.
     */
    public const string REQUEUED_UNSENT = 'This delivery was interrupted before it was sent. Nothing was sent; it has been put back in the queue.';

    public const string REQUEUED_MAYBE_SENT = 'This delivery was interrupted before its result was recorded. It may have reached the website; it is '
        .'being re-sent with the same delivery id so the website can recognise a repeat.';

    /** `sprintf` formats, with the number of interruptions. */
    public const string ABANDONED_UNSENT = 'This delivery was interrupted %d times in a row and was never attempted. Nothing has been '
        .'sent. Check the queue workers, then replay it.';

    public const string ABANDONED_MAYBE_SENT = 'This delivery was interrupted %d times in a row before its result was recorded. It may have '
        .'reached the website, so check there before replaying it.';

    /**
     * How far past `retry_after` the threshold must reach, whatever config says.
     *
     * One sweep interval. At `retry_after` Redis only moves the abandoned job
     * back to the ready list; it is failed for exceeding its one try when a
     * worker next takes it, and that has to have happened before the sweep
     * sends a replacement. The minute is the room for it — and if the lane is
     * too busy to take it that quickly, the backlog guard in the sweep holds
     * off anyway.
     */
    private const int MARGIN_SECONDS = 60;

    /** The sweeper's re-queue notes: the delivery is late, not refused. */
    public static function isRequeueNote(?string $error): bool
    {
        return in_array($error, [self::REQUEUED_UNSENT, self::REQUEUED_MAYBE_SENT], true)
            // Written before these were constants.
            || str_contains((string) $error, 'never reported back');
    }

    /** The sweeper's give-up notes: out of sweeps, a dead letter. */
    public static function isAbandonedNote(?string $error): bool
    {
        return in_array($error, [
            sprintf(self::ABANDONED_UNSENT, self::MAX_SWEEPS + 1),
            sprintf(self::ABANDONED_MAYBE_SENT, self::MAX_SWEEPS + 1),
        ], true) || str_contains((string) $error, 'found abandoned');
    }

    /** The moment before which a due row is presumed abandoned. */
    public static function cutoff(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->copy()->subSeconds(self::seconds());
    }

    /**
     * Every delivery overdue past the threshold.
     *
     * Scoped to whatever the caller's tenancy already is: the sweeper asks
     * across projects, the delivery log asks inside one. Never aged on
     * `updated_at`: an unrelated write would push it forward and quietly hide a
     * stranded row forever. `created_at` and `next_attempt_at` are only written
     * by the transitions that queue a job.
     *
     * @param  Builder<WebhookDelivery>  $query
     * @return Builder<WebhookDelivery>
     */
    public static function scope(Builder $query, ?Carbon $now = null): Builder
    {
        [$sql, $bindings] = self::condition($now);

        return $query->whereRaw("({$sql})", $bindings);
    }

    /**
     * The same test as raw SQL, for the delivery log's ordering — which cannot
     * take a builder, and must not be a second copy of the rule.
     *
     * The statuses are written out rather than bound, and the expression is
     * spelled exactly as the partial index `webhook_deliveries_overdue_index`
     * spells it: Postgres only uses a partial index when it can see the query
     * implies the index's own `where`, and a bound parameter hides that. The
     * sweep asks this every minute across every project, so it matters.
     *
     * @return array{0: literal-string, 1: list<Carbon>}
     */
    public static function condition(?Carbon $now = null): array
    {
        return [
            "status in ('pending', 'retrying') and coalesce(next_attempt_at, created_at) <= ?",
            [self::cutoff($now)],
        ];
    }

    /** Whether this row, as it stands, is one of them. */
    public static function includes(WebhookDelivery $delivery, ?Carbon $now = null): bool
    {
        $due = $delivery->next_attempt_at ?? $delivery->created_at;

        return in_array($delivery->status, [DeliveryStatus::Pending, DeliveryStatus::Retrying], true)
            && $due !== null
            && $due->lessThanOrEqualTo(self::cutoff($now));
    }

    /**
     * The threshold in force, which config may raise or lower — but not below
     * the `publishing` connection's `retry_after` plus a sweep.
     *
     * Configurable because the arithmetic above depends on the queue connection
     * an installation runs. Floored against the connection it actually runs,
     * rather than against a fixed number, so that raising `retry_after` and
     * forgetting this cannot make the sweeper dispatch over a worker that is
     * still making its request.
     */
    public static function seconds(): int
    {
        $configured = config('publishing.stranded_after', self::AFTER_SECONDS);
        $retryAfter = config('queue.connections.'.config('publishing.connection').'.retry_after');

        return max(
            (is_numeric($retryAfter) ? (int) $retryAfter : 0) + self::MARGIN_SECONDS,
            is_numeric($configured) ? (int) $configured : self::AFTER_SECONDS,
        );
    }
}
