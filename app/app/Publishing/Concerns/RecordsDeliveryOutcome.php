<?php

declare(strict_types=1);

namespace App\Publishing\Concerns;

use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Models\WebhookDelivery;
use App\Publishing\ThreadsPublisher;
use App\Publishing\WebhookPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * How a delivery ends, for every transport (§9).
 *
 * §9 is explicit that a second channel changes the transport and not the
 * mechanics: "Таблица доставок, бэкофф и replay остаются общими: меняется
 * транспорт, а не механика." {@see WebhookPublisher} and
 * {@see ThreadsPublisher} had written that sentence out twice, and the two
 * copies had already drifted — one recorded the response code on a retry and
 * the other left a stale one on the row, one recorded the status it was given
 * and the other a literal 200. Neither divergence was a decision. They are the
 * ordinary fate of a hundred and forty duplicated lines, and the only fix that
 * holds is for there to be one copy.
 *
 * What is deliberately *not* here is anything a transport knows: which failures
 * are retryable, what a status code means, when a unit counts as published. A
 * publisher decides those and then says so through one of the four writers
 * below.
 */
trait RecordsDeliveryOutcome
{
    /**
     * How many times a delivery may be put off before it is a dead letter.
     *
     * A deferral is not a failed attempt — the ladder counts refusals and a
     * full window is a good post at a bad moment — so nothing in the backoff
     * bounds it, and without a bound of its own a delivery whose obstacle never
     * clears re-dispatches forever. Eight is long enough that a busy account
     * finishes normally and short enough that a stuck one is visible: at the
     * limiter's blind probe of fifteen minutes it is two hours, and at a real
     * sliding window it is eight windows.
     */
    private const int MAX_DEFERRALS = 8;

    /** How this transport names itself in the dead-letter log. */
    abstract protected function transportName(): string;

    /**
     * Delivered, with the status the transport was actually given.
     *
     * A ping is the connection wizard's third step in both transports, and
     * `verified_at` is what both screens read, so it is set here rather than
     * being a thing each publisher remembers to do.
     */
    protected function settleDelivered(
        WebhookDelivery $delivery,
        int $attempt,
        int $latency,
        int $status,
    ): WebhookDelivery {
        $delivery->forceFill([
            'status' => DeliveryStatus::Delivered,
            'attempts' => $attempt,
            'response_code' => $status,
            'latency_ms' => $latency,
            'delivered_at' => now(),
            'next_attempt_at' => null,
            'error' => null,
        ])->save();

        if (($delivery->payload_snapshot['event'] ?? null) === WebhookEvent::Ping->value) {
            $delivery->channel->forceFill(['verified_at' => now()])->save();
        }

        return $delivery;
    }

    /**
     * One rung of §6.2's published ladder, or the end of it.
     *
     * The ladder lives in config and is walked here rather than being left to
     * the queue, because a receiver operator plans around "1m → 5m → 30m → 2h →
     * 12h" and a worker's own retry policy has no idea what was promised to
     * anybody. The status is written even when it is null: a row that retried
     * after a timeout must not still be showing the 503 that preceded it.
     */
    protected function scheduleRetry(
        WebhookDelivery $delivery,
        int $attempt,
        int $latency,
        ?int $status,
        string $error,
    ): WebhookDelivery {
        /** @var list<int> $ladder */
        $ladder = config('publishing.backoff', []);

        if ($attempt >= count($ladder)) {
            return $this->deadLetter($delivery, $error, $attempt, $latency, $status);
        }

        $delay = $ladder[$attempt];

        $delivery->forceFill([
            'status' => DeliveryStatus::Retrying,
            'attempts' => $attempt,
            'response_code' => $status,
            'latency_ms' => $latency,
            'error' => $error,
            'next_attempt_at' => now()->addSeconds($delay),
        ])->save();

        return $delivery;
    }

    /**
     * Come back later, without spending a rung.
     *
     * The state a delivery is in when nothing went wrong and it still cannot go
     * out: the account's publishing window is full, or another delivery holds
     * the publication. Both are waits rather than failures, so `attempts` is
     * left where it was and the wake-up time comes from the obstacle rather
     * than from the ladder — which would wake the job in a minute to be told
     * the same thing.
     *
     * Waits are counted anyway, in their own column, because an obstacle that
     * never clears is indistinguishable from a lost row. `$exhausted` is the
     * reason an operator reads when that happens, and it is a different reason
     * from a refusal: nothing was ever sent.
     */
    protected function defer(
        WebhookDelivery $delivery,
        Carbon $at,
        string $error,
        string $exhausted,
    ): WebhookDelivery {
        $deferrals = $delivery->deferrals + 1;

        if ($deferrals > self::MAX_DEFERRALS) {
            return $this->deadLetter($delivery, $exhausted, $delivery->attempts);
        }

        $delivery->forceFill([
            'status' => DeliveryStatus::Retrying,
            'deferrals' => $deferrals,
            'response_code' => null,
            'error' => $error,
            'next_attempt_at' => $at,
        ])->save();

        return $delivery;
    }

    /** Out of attempts, or refused for a reason retrying cannot fix. */
    protected function deadLetter(
        WebhookDelivery $delivery,
        string $error,
        int $attempt = 0,
        int $latency = 0,
        ?int $status = null,
    ): WebhookDelivery {
        Log::warning("A {$this->transportName()} delivery went to dead letter", [
            'delivery' => $delivery->delivery_id,
            'channel' => $delivery->channel_id,
            'attempts' => $attempt,
            'error' => $error,
        ]);

        $delivery->forceFill([
            'status' => DeliveryStatus::DeadLetter,
            'attempts' => max($attempt, $delivery->attempts),
            'response_code' => $status,
            'latency_ms' => $latency ?: $delivery->latency_ms,
            'error' => $error,
            'next_attempt_at' => null,
        ])->save();

        return $delivery;
    }

    /**
     * Live, because a destination said so.
     *
     * The first destination to confirm is enough: the unit is reachable. A
     * second one failing is a delivery problem, which the deliveries log is
     * for — it does not un-publish something that is already out.
     */
    protected function markUnitPublished(WebhookDelivery $delivery): void
    {
        $unit = $delivery->contentItem;

        if ($unit === null || $unit->state !== ContentItemState::Approved) {
            return;
        }

        $unit->markPublished();
    }

    protected function elapsed(int|float $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
