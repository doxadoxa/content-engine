<?php

declare(strict_types=1);

namespace App\Publishing\Concerns;

use App\Content\ArticleBusinessFacts;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Http\Controllers\ApprovalController;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Publishing\Articles\ArticleDeliveryGuard;
use App\Publishing\ConnectionFingerprint;
use App\Publishing\HeldArticles;
use App\Publishing\WebhookPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * How a delivery ends, for every transport (§9).
 *
 * §9 is explicit that a second channel changes the transport and not the
 * mechanics: "Таблица доставок, бэкофф и replay остаются общими: меняется
 * транспорт, а не механика." {@see WebhookPublisher} and the other transports
 * had written that sentence out more than once, and the copies had already
 * drifted — one recorded the response code on a retry and another left a stale
 * one on the row, one recorded the status it was given and another a literal
 * 200. Neither divergence was a decision. They are the ordinary fate of a
 * hundred and forty duplicated lines, and the only fix that holds is for there
 * to be one copy.
 *
 * What is deliberately *not* here is anything a transport knows: which failures
 * are retryable, what a status code means, when a unit counts as published. A
 * publisher decides those and then says so through one of the four writers
 * below.
 */
trait RecordsDeliveryOutcome
{
    public const string WAITING_FOR_WEBSITE = "Waiting for your website: its connection isn't working. Avyo sends this article as soon as a test passes.";

    /** The owner paused the website. Waits as long as the pause lasts: Resume sends it. */
    public const string WAITING_FOR_RESUME = 'Waiting for your website: it is paused. Avyo sends this article as soon as you resume it.';

    /** What a delivery that waited a day for a broken website says when it gives up. */
    public const string WEBSITE_STAYED_BROKEN = "Your website's connection stayed broken for a day, so this article wasn't sent. Test the connection on the Website page, then use Try again.";

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

    /** How long an article waits between looks at a website that isn't working: a day, over the deferral limit. */
    private const int WEBSITE_WAIT_HOURS = 3;

    /** Between looks at a paused website. Resume sends it at once; this is only the fallback. */
    private const int PAUSED_WAIT_HOURS = 12;

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
            'sweeps' => 0,
            'deferrals' => 0,
        ])->save();

        // Only a test of the connection as it is now. One signed with a
        // secret or sent to an address the owner has since replaced says
        // nothing about the new one.
        if ($this->isCurrentTest($delivery)) {
            $delivery->channel->forceFill(['verified_at' => now()])->save();
        }

        if ($delivery->article_schedule_id !== null) {
            ArticleSchedule::query()->whereKey($delivery->article_schedule_id)
                ->where('version', $delivery->article_schedule_version)->where('delivery_id', $delivery->id)
                ->update(['status' => 'completed', 'blocked_reason' => null]);
        }

        return $delivery;
    }

    /**
     * One rung of §6.2's published ladder, or the end of it — straight to the
     * end for a `ping`.
     *
     * The ladder lives in config and is walked here rather than being left to
     * the queue, because a receiver operator plans around "1m → 5m → 30m → 2h →
     * 12h" and a worker's own retry policy has no idea what was promised to
     * anybody. The status is written even when it is null: a row that retried
     * after a timeout must not still be showing the 503 that preceded it.
     *
     * `sweeps` goes back to zero here and on delivery, because both mean a
     * worker got as far as an answer. The sweep limit is about a delivery that
     * keeps losing its worker, and losses either side of a real attempt are
     * not the same run of bad luck.
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

        // A test is a question somebody is waiting on, not an article that
        // has to arrive eventually. Put on the ladder it read "Testing…" for
        // twelve hours while the owner waited to be told what was wrong; one
        // attempt, answered now, is what the connect screen needs.
        $isTest = ($delivery->payload_snapshot['event'] ?? null) === WebhookEvent::Ping->value;

        if ($isTest || $attempt >= count($ladder)) {
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
            'sweeps' => 0,
            // A real attempt ends any wait: the next outage gets its own day.
            'deferrals' => 0,
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
    /**
     * Refuse to send content the operator has taken back.
     *
     * A delivery carries a payload snapshot and sends it whatever the unit has
     * done since. That was safe for exactly as long as `approved` had one edge
     * and it pointed at `published`: nothing could un-approve a unit, so nothing
     * could invalidate a queued delivery. Sending back for rework added that
     * edge and this is the other half of it — without it an operator can pull an
     * inaccurate article and have the version they pulled published a minute
     * later by a job that was already in the queue.
     *
     * Checked inside the delivery lock, immediately before the request goes
     * out, because that is the only place the answer cannot go stale. Cancelling
     * the rows on the way back ({@see ApprovalController::reject()})
     * handles the ones sitting in the queue; this handles the one already in
     * flight.
     */
    protected function refuseIfWithdrawn(WebhookDelivery $delivery): ?WebhookDelivery
    {
        $unit = $delivery->contentItem;

        if ($unit === null) {
            return null;
        }

        // Dead-lettered and handed back in one transaction: an Approve landing
        // between the two would relink a delivery that was still retrying.
        //
        // Decided again under the schedule's row lock: an Approve that
        // committed after the first look has settled the question, and a
        // hand-back decided before it would overwrite a person's approval.
        if (app(ArticleDeliveryGuard::class)->verdict($delivery) !== null) {
            $projectId = $unit->project_id;
            $dead = DB::transaction(function () use ($delivery, $projectId): ?WebhookDelivery {
                // Project first, as dispatch(), mutate() and approval take
                // it: the same order everywhere is what keeps a concurrent
                // Approve from deadlocking against this.
                Project::query()->whereKey($projectId)->lockForUpdate()->first();
                ArticleSchedule::query()->where('content_item_id', $delivery->content_item_id)->lockForUpdate()->first();
                $delivery->unsetRelation('contentItem');
                $verdict = app(ArticleDeliveryGuard::class)->verdict($delivery);
                if ($verdict === null) {
                    return null;
                }
                $dead = $this->deadLetter($delivery, $verdict->reason);
                $verdict->handBack();

                return $dead;
            });
            if ($dead !== null) {
                return $dead;
            }
            $unit = $delivery->contentItem ?? $unit;
        }
        $refusal = app(ArticleBusinessFacts::class)->refusal($unit);
        if ($refusal !== null) {
            return $this->deadLetter($delivery, $refusal);
        }

        // `refreshing` is on the list deliberately. It is live text being
        // rewritten, not text somebody withdrew, and the delivery in flight is
        // the version readers currently have — killing it would be the guard
        // doing harm rather than preventing it. What this refuses is the states
        // before a human ever said yes.
        $sendable = [
            ContentItemState::Approved,
            ContentItemState::Published,
            ContentItemState::Refreshing,
        ];

        if (! in_array($unit->state, $sendable, true)) {
            return $this->deadLetter(
                $delivery,
                'The unit was sent back for rework before this delivery went out, so it was not sent.',
            );
        }

        // A website that is paused or failed its last test is a wait, not a
        // verdict: the article waits without spending an attempt, and Resume
        // or a passing test sends it on ({@see HeldArticles::resume()}).
        // Dead-lettering it here left "the connection isn't working" on the
        // article long after the connection was fixed.
        //
        // A pause is the owner's own choice and lasts as long as they like,
        // so it does not spend the day a broken website is given.
        if (app(ArticleDeliveryGuard::class)->websiteUnusable($delivery) && ! $delivery->channel->is_enabled) {
            $delivery->forceFill([
                'status' => DeliveryStatus::Retrying,
                'response_code' => null,
                'error' => self::WAITING_FOR_RESUME,
                'next_attempt_at' => now()->addHours(self::PAUSED_WAIT_HOURS),
                // A fresh start for the broken-website day, should it come
                // after the owner resumes.
                'deferrals' => 0,
            ])->save();

            return $delivery;
        }
        if (app(ArticleDeliveryGuard::class)->websiteUnusable($delivery)) {
            return $this->defer(
                $delivery,
                now()->addHours(self::WEBSITE_WAIT_HOURS),
                self::WAITING_FOR_WEBSITE,
                self::WEBSITE_STAYED_BROKEN,
            );
        }

        return null;
    }

    /** Persist the possibility of a remote effect before crossing the transport boundary. */
    protected function markArticleAttemptStarted(WebhookDelivery $delivery): void
    {
        if ($delivery->article_schedule_id !== null && $delivery->article_attempt_started_at === null) {
            $delivery->forceFill(['article_attempt_started_at' => now()])->save();
        }
    }

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

        // A failed test is the website's current answer, so it stops being
        // used until a test passes. Otherwise the page says "Couldn't
        // connect" while articles keep being sent to it — the two would
        // disagree about the one question the page exists to answer.
        if ($this->isCurrentTest($delivery)) {
            $channel = $delivery->channel;
            $channel->forceFill([
                'verified_at' => null,
                'config' => [...$channel->config, 'article_publishing_verified' => false],
            ])->save();
        }

        return $delivery;
    }

    /**
     * Whether this delivery is a test of the website connection as it is
     * now — the only kind whose answer may change `verified_at`.
     *
     * And the newest test of it: two tests of an unchanged connection can
     * finish in either order, and the one the owner pressed last is the
     * answer they are waiting for. An older one finishing later would
     * otherwise undo it — clear a pass, or verify over a failure and send
     * the articles held for it. ConnectionHealth reads the newest test too.
     */
    protected function isCurrentTest(WebhookDelivery $delivery): bool
    {
        if (($delivery->payload_snapshot['event'] ?? null) !== WebhookEvent::Ping->value) {
            return false;
        }

        $channel = $delivery->channel;

        return $channel instanceof Channel
            && ConnectionFingerprint::stillCurrent($delivery, $channel->refresh())
            && ! $this->newerTestExists($delivery);
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

    private function newerTestExists(WebhookDelivery $delivery): bool
    {
        return WebhookDelivery::query()
            ->where('channel_id', $delivery->channel_id)
            ->whereNull('content_item_id')
            ->where('payload_snapshot->event', WebhookEvent::Ping->value)
            ->whereKeyNot($delivery->getKey())
            ->where(fn ($query) => $query->where('created_at', '>', $delivery->created_at)
                ->orWhere(fn ($same) => $same->where('created_at', $delivery->created_at)->where('id', '>', $delivery->getKey())))
            ->exists();
    }
}
