<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use App\Publishing\Concerns\RecordsDeliveryOutcome;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\PublishingBacklog;
use App\Publishing\StrandedDeliveries;
use App\Publishing\WebhookPublisher;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan publish:sweep-stranded` — the way out of a promise nobody is
 * keeping (§9).
 *
 * §9 keeps the delivery table, the backoff and the replay common across
 * transports, and all three assume something eventually writes an outcome onto
 * a row. Nothing does when the worker holding its job is restarted: `$tries = 1`
 * on {@see DeliverWebhookJob} means the job Redis offers again is failed rather
 * than run. A `pending` row then has nothing left that would touch it —
 * `queue()`'s `firstOrCreate` on `dispatch_key` refuses to make a second row —
 * and a `retrying` row has lost the one delayed job that was going to. The
 * post is approved, the owner was told it is on its way, and it never goes out,
 * with no screen saying so, because both statuses are what a healthy row looks
 * like while it waits.
 *
 * This is the floor under that. It is deliberately not a retry policy: it does
 * not decide anything about the delivery, it only puts a row that nothing owns
 * back where the ordinary machinery can pick it up. Everything about *how* to
 * publish stays with the publisher.
 *
 * **Every minute, four minutes late.** Publishing has its own queue connection
 * with a `retry_after` of 150 seconds, so {@see StrandedDeliveries} can call a
 * row abandoned at 240 and the schedule can look every minute. A restart during
 * a deploy now costs a delivery about five minutes, not seventy.
 *
 * **Not while the lane has a queue.** Age alone cannot tell a lost job from
 * one that is waiting its turn: behind a backlog, or with Horizon down, a
 * healthy row is four minutes old and its job still sitting in Redis. Sweeping
 * it queues a second copy, and sweeping it three times dead-letters an article
 * whose job is still coming. So while delivery jobs are waiting
 * ({@see PublishingBacklog}) the sweep holds off — when nothing is waiting, a
 * row nobody attempted is one whose job is gone.
 *
 * But not for ever: a lane that never drains — a run of slow receivers, a
 * worker pool too small for the day — would otherwise switch recovery off
 * with no end. Past {@see self::PATIENCE_SECONDS} overdue, a row is swept
 * whatever is waiting. That is safe to do because a second job for a row is
 * harmless now: it waits for the delivery lock, finds a settled row settled,
 * leaves a scheduled retry to the job scheduled for it, and anything it does
 * send carries the same `delivery_id`.
 *
 * **A sweep is counted, not an attempt.** It does not walk §6.2's five
 * attempts, and it has its own counter rather than borrowing `deferrals`,
 * which bounds a different wait. What it does spend is bounded
 * ({@see StrandedDeliveries::MAX_SWEEPS}), because a delivery whose worker
 * keeps disappearing is not one to keep waking up: past the bound the row
 * becomes a dead letter, which is the one status §7's screen already puts at
 * the top and offers a button for.
 *
 * **The same delivery id, so a repeat is recognisable.** The row is re-sent,
 * not replaced. If the lost attempt did reach the receiver, the second arrives
 * with the same `delivery_id`, which is what receivers dedupe on — and the
 * message the owner reads says which of the two it was, when the row can tell.
 *
 * **Across projects, like the queue itself.** A stranded delivery has no
 * operator sitting in front of it and no tenant in context; the row names its
 * project and the dispatch runs inside it.
 */
class PublishSweepStrandedCommand extends Command
{
    /**
     * How long an overdue row waits for a backlog to clear before it is swept
     * anyway, in seconds.
     *
     * Long enough that an ordinary busy spell — a batch of scheduled
     * articles, a receiver answering slowly for a few minutes — drains first
     * and nothing is sent twice for it. Short enough that an owner whose
     * article was lost is not told "in progress" for half a day because
     * somebody else's receiver is slow. Half an hour is still well inside
     * the ladder's own second rung.
     */
    public const int PATIENCE_SECONDS = 1_800;

    protected $signature = 'publish:sweep-stranded
        {--limit=200 : How many stranded deliveries one sweep may take}';

    protected $description = 'Return deliveries nobody is attempting to the retry ladder';

    public function handle(CurrentProject $current, PublishingBacklog $backlog): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $query = StrandedDeliveries::scope(WebhookDelivery::acrossProjects());

        if (! $query->clone()->exists()) {
            // The ordinary answer, and a success. A sweep that found nothing is
            // a queue that is working.
            $this->components->info('No delivery is stranded.');

            return self::SUCCESS;
        }

        $waiting = $backlog->waiting();
        $overdue = 0;

        if ($waiting > 0) {
            // Not a failure of the sweep, and not a reason to spend one on a
            // row whose job may be among those waiting. Only rows that have
            // outwaited any reasonable backlog go ahead. In SQL, before the
            // limit: filtered afterwards, a batch of rows that only just fell
            // due could fill the limit every minute and keep a row that has
            // been overdue for hours from ever being looked at.
            $overdue = $query->clone()->count();
            $query->whereRaw(
                'coalesce(next_attempt_at, created_at) <= ?',
                [Carbon::now()->subSeconds(self::PATIENCE_SECONDS)],
            );
        }

        // Longest overdue first, measured the way StrandedDeliveries measures
        // it — not by `created_at`, which a replayed or retrying row keeps from
        // long before it fell due. The same expression as the partial index,
        // so the index can hand the rows over already in order.
        $stranded = $query
            ->orderByRaw('coalesce(next_attempt_at, created_at)')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($waiting > 0) {
            // A warning rather than a notice, because a lane that stays backed
            // up is the thing somebody should look at.
            Log::warning('The publishing queue is backed up; only long-overdue deliveries were swept', [
                'waiting_jobs' => $waiting,
                'overdue_deliveries' => $overdue,
                'swept_anyway' => $stranded->count(),
            ]);
            $this->components->warn("{$waiting} delivery jobs are still waiting to run.");

            if ($stranded->isEmpty()) {
                return self::SUCCESS;
            }
        }

        $recovered = 0;
        $abandoned = 0;

        foreach ($stranded as $delivery) {
            $current->run($delivery->project_id, function () use ($delivery, &$recovered, &$abandoned): void {
                $outcome = $this->sweep($delivery);

                if ($outcome === 'requeued') {
                    $recovered++;
                } elseif ($outcome === 'dead letter') {
                    $abandoned++;
                }
            });
        }

        $this->components->twoColumnDetail('Returned to the ladder', (string) $recovered);

        if ($abandoned > 0) {
            $this->components->twoColumnDetail('Dead-lettered', (string) $abandoned);
        }

        return self::SUCCESS;
    }

    /**
     * One row, under the lock an attempt holds.
     *
     * The row was read before this ran, and at four minutes a job that was
     * merely stuck behind a busy queue can start while the sweep is deciding.
     * Taking the attempt's own lock, and asking again inside it, means the
     * sweep either sees the attempt's outcome or keeps the attempt out until
     * it has written its own — never overwrites a live one. The job is
     * dispatched after the lock is released, for the reason `attempt()` gives:
     * on a fast worker it would otherwise find the lock and do nothing.
     */
    private function sweep(WebhookDelivery $delivery): ?string
    {
        $lock = Cache::lock('webhook-delivery:'.$delivery->getKey(), WebhookPublisher::lockSeconds());

        if (! $lock->get()) {
            return null;
        }

        try {
            $delivery->refresh();

            if (! StrandedDeliveries::includes($delivery)) {
                return null;
            }

            if ($delivery->sweeps >= StrandedDeliveries::MAX_SWEEPS) {
                $this->abandon($delivery);

                return 'dead letter';
            }

            $this->markRequeued($delivery);
        } finally {
            $lock->release();
        }

        DeliverWebhookJob::dispatch($delivery->getKey())->afterCommit();

        $this->components->twoColumnDetail($delivery->delivery_id, 'requeued');

        return 'requeued';
    }

    /**
     * Back into the queue, with the row saying what happened to it.
     *
     * `retrying` rather than left at `pending`, because that is the status the
     * rest of the engine reads as "in the ladder", and due now — which is also
     * what lets the next sweep find it again if this dispatch is lost too. The
     * error text is written for the owner reading the log, not for a developer
     * reading a stack trace, and it claims nothing was sent only when the row
     * can prove it.
     */
    private function markRequeued(WebhookDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::Retrying,
            'sweeps' => $delivery->sweeps + 1,
            'error' => $this->provablyUnsent($delivery)
                ? 'This delivery was interrupted before it was sent. Nothing was sent; it has been put back in the queue.'
                : 'This delivery was interrupted before its result was recorded. It may have reached the website; it is '
                    .'being re-sent with the same delivery id so the website can recognise a repeat.',
            'next_attempt_at' => now(),
        ])->save();

        Log::notice('A stranded delivery was returned to the queue', [
            'delivery' => $delivery->delivery_id,
            'channel' => $delivery->channel_id,
            'sweeps' => $delivery->sweeps,
            'maybe_sent' => ! $this->provablyUnsent($delivery),
        ]);
    }

    /**
     * Out of sweeps — a person has to look at it.
     *
     * A dead letter and not a silent abandonment, because that is the status
     * §7's delivery log sorts to the top and puts a replay button beside. The
     * distinction the message has to carry is whether anything was sent: a
     * dead letter after five refusals means the receiver said no, and one that
     * was never attempted means nobody ever asked it.
     */
    private function abandon(WebhookDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::DeadLetter,
            'error' => $this->provablyUnsent($delivery)
                ? sprintf(
                    'This delivery was interrupted %d times in a row and was never attempted. Nothing has been '
                        .'sent. Check the queue workers, then replay it.',
                    StrandedDeliveries::MAX_SWEEPS + 1,
                )
                : sprintf(
                    'This delivery was interrupted %d times in a row before its result was recorded. It may have '
                        .'reached the website, so check there before replaying it.',
                    StrandedDeliveries::MAX_SWEEPS + 1,
                ),
            'next_attempt_at' => null,
        ])->save();

        Log::warning('A stranded delivery ran out of sweeps and became a dead letter', [
            'delivery' => $delivery->delivery_id,
            'channel' => $delivery->channel_id,
        ]);

        $this->components->twoColumnDetail($delivery->delivery_id, 'dead letter');
    }

    /**
     * Whether the row itself shows no request went out.
     *
     * Only an article delivery can: it stamps `article_attempt_started_at` and
     * saves it before the request is made
     * ({@see RecordsDeliveryOutcome::markArticleAttemptStarted()}), so an empty
     * stamp is proof. Every other delivery records nothing until the answer
     * comes back, and a worker that stopped mid-request looks exactly like one
     * that never started — so for those, and for an article whose stamp is set,
     * the honest thing to say is "may have".
     */
    private function provablyUnsent(WebhookDelivery $delivery): bool
    {
        return $delivery->article_schedule_id !== null && $delivery->article_attempt_started_at === null;
    }
}
