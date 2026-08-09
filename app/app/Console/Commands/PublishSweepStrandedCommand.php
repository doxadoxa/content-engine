<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\StrandedDeliveries;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan publish:sweep-stranded` — the way out of `pending` (§9).
 *
 * §9 keeps the delivery table, the backoff and the replay common across
 * transports, and all three assume something eventually writes an outcome onto
 * a row. Nothing does when the worker holding it is killed: `$tries = 1` on
 * {@see DeliverWebhookJob} means the requeued job is failed rather than run, the
 * re-dispatch path only looks at `retrying`, and `queue()`'s `firstOrCreate` on
 * `dispatch_key` refuses to make a second row. The post is approved, the
 * operator was told it was queued, and it never goes out — with no screen
 * saying so, because `pending` is what a healthy row looks like for its first
 * second of life.
 *
 * This is the floor under that. It is deliberately not a retry policy: it does
 * not decide anything about the delivery, it only puts a row that nothing owns
 * back where the ordinary machinery can pick it up. Everything about *how* to
 * publish stays with the publisher.
 *
 * **A sweep spends a deferral, not a rung of the ladder.** Nothing was sent, so
 * §6.2's five attempts are untouched — the same accounting a full publishing
 * window gets. What it does spend is bounded ({@see StrandedDeliveries::MAX_SWEEPS}),
 * because a worker being killed every half hour is not a delivery to keep
 * waking up: past the bound the row becomes a dead letter, which is the one
 * status §7's screen already puts at the top and offers a button for.
 *
 * **Across projects, like the queue itself.** A stranded delivery has no
 * operator sitting in front of it and no tenant in context; the row names its
 * project and the dispatch runs inside it.
 */
class PublishSweepStrandedCommand extends Command
{
    protected $signature = 'publish:sweep-stranded
        {--limit=200 : How many stranded deliveries one sweep may take}';

    protected $description = 'Return deliveries stuck pending past their age to the retry ladder';

    public function handle(CurrentProject $current): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $stranded = StrandedDeliveries::scope(WebhookDelivery::acrossProjects())
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($stranded->isEmpty()) {
            // The ordinary answer, and a success. A sweep that found nothing is
            // a queue that is working.
            $this->components->info('No delivery is stranded.');

            return self::SUCCESS;
        }

        $recovered = 0;
        $abandoned = 0;

        foreach ($stranded as $delivery) {
            $current->run($delivery->project_id, function () use ($delivery, &$recovered, &$abandoned): void {
                if ($delivery->deferrals >= StrandedDeliveries::MAX_SWEEPS) {
                    $this->abandon($delivery);
                    $abandoned++;

                    return;
                }

                $this->requeue($delivery);
                $recovered++;
            });
        }

        $this->components->twoColumnDetail('Returned to the ladder', (string) $recovered);

        if ($abandoned > 0) {
            $this->components->twoColumnDetail('Dead-lettered', (string) $abandoned);
        }

        return self::SUCCESS;
    }

    /**
     * Back into the queue, with the row saying what happened to it.
     *
     * `retrying` rather than left at `pending`, because that is the status the
     * rest of the engine reads as "in the ladder" — and because a row that
     * stayed `pending` would be swept again on the next run whether or not this
     * dispatch worked. The error text is written for the operator reading the
     * log, not for a developer reading a stack trace: nothing failed, a machine
     * went away.
     */
    private function requeue(WebhookDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::Retrying,
            'deferrals' => $delivery->deferrals + 1,
            'error' => 'The worker holding this delivery never reported back — it was most likely killed '
                .'mid-flight. Nothing was sent; the delivery has been put back in the queue.',
            'next_attempt_at' => now(),
        ])->save();

        DeliverWebhookJob::dispatch($delivery->getKey())
            ->onQueue((string) config('publishing.queue'))
            ->afterCommit();

        Log::notice('A stranded delivery was returned to the queue', [
            'delivery' => $delivery->delivery_id,
            'channel' => $delivery->channel_id,
            'sweeps' => $delivery->deferrals,
        ]);

        $this->components->twoColumnDetail($delivery->delivery_id, 'requeued');
    }

    /**
     * Out of sweeps — a person has to look at it.
     *
     * A dead letter and not a silent abandonment, because that is the status
     * §7's delivery log sorts to the top and puts a replay button beside. The
     * distinction the message has to carry is that nothing was ever sent: a
     * dead letter after five refusals means the receiver said no, and this one
     * means nobody ever asked it.
     */
    private function abandon(WebhookDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::DeadLetter,
            'error' => sprintf(
                'This delivery was found abandoned %d times and was never attempted — the worker that '
                    .'picked it up stopped reporting each time. Nothing has been sent. Check the queue '
                    .'workers, then replay it.',
                StrandedDeliveries::MAX_SWEEPS,
            ),
            'next_attempt_at' => null,
        ])->save();

        Log::warning('A stranded delivery ran out of sweeps and became a dead letter', [
            'delivery' => $delivery->delivery_id,
            'channel' => $delivery->channel_id,
        ]);

        $this->components->twoColumnDetail($delivery->delivery_id, 'dead letter');
    }
}
