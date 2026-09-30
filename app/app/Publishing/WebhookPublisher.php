<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Content\ArticleBusinessFacts;
use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Pages\RegisterPublishedArticle;
use App\Publishing\Articles\ArticleDeliveryGuard;
use App\Publishing\Concerns\RecordsDeliveryOutcome;
use App\Publishing\Contracts\ChannelPublisher;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Delivery, and everything that happens when it does not work (§6.1–6.2).
 *
 * The retry ladder is published in the contract, so it lives in config and is
 * walked here rather than being left to the queue: a receiver operator plans
 * around "1m → 5m → 30m → 2h → 12h", and a worker's own retry policy has no
 * idea what was promised to anybody.
 *
 * §9 makes this one transport among several rather than the whole of
 * publishing. What used to sit at the top of this class — three methods that
 * each ended in `where('type', ChannelType::Webhook)` — was never webhook
 * mechanics; it was choosing an audience, and it now lives in
 * {@see PublishToChannels}. What is left is the part that genuinely knows about
 * HMAC, endpoints and a `public_url` coming back in a JSON body.
 */
class WebhookPublisher implements ChannelPublisher
{
    /** §9's "механика", shared with every transport. */
    use RecordsDeliveryOutcome;

    /** How early a retry may run and still count as due. */
    private const int DUE_GRACE_SECONDS = 5;

    public function __construct(
        protected readonly PublicHttpTarget $targets,
        private readonly PublishToChannels $channels,
    ) {}

    public function supports(ChannelType $type): bool
    {
        return $type === ChannelType::Webhook;
    }

    /** A signed `ping` is the whole third step of the connection wizard. */
    public function canPing(): bool
    {
        return true;
    }

    /** Nothing about a receiver needs a person watching it. */
    public function canAutopublish(): bool
    {
        return true;
    }

    /**
     * Publish a unit to every enabled channel that can take it.
     *
     * Selection moved to {@see PublishToChannels} in 12.2a; these three stay as
     * the way phase 6's delivery test says publishing is triggered, and that
     * test is the contract for everything below them. They forward and add
     * nothing — a caller that wants to publish should ask the collaborator, and
     * every caller in the application now does.
     *
     * @return list<WebhookDelivery>
     */
    public function publish(ContentItem $unit): array
    {
        return $this->channels->publish($unit);
    }

    /** @return list<WebhookDelivery> */
    public function publishAutomatically(ContentItem $unit): array
    {
        return $this->channels->publishAutomatically($unit);
    }

    /** @return list<WebhookDelivery> */
    public function publishManually(ContentItem $unit): array
    {
        return $this->channels->publishManually($unit);
    }

    /**
     * Send a signed `ping` — the third step of the connection wizard, and the
     * only one that proves anything.
     *
     * Needs no content unit: a channel is connected before the project has
     * written a word, and demanding a carrier made the wizard unusable at
     * exactly the moment it is used.
     */
    public function ping(Channel $channel, Project $project): WebhookDelivery
    {
        $deliveryId = WebhookPayload::newDeliveryId();

        $delivery = WebhookDelivery::query()->create([
            'channel_id' => $channel->getKey(),
            'content_item_id' => null,
            'delivery_id' => $deliveryId,
            'status' => DeliveryStatus::Pending->value,
            'payload_snapshot' => WebhookPayload::ping($project, $deliveryId),
            // Its answer counts only while the connection is still this one.
            'connection_fingerprint' => ConnectionFingerprint::of($channel),
        ]);

        DeliverWebhookJob::dispatch($delivery->getKey())->afterCommit();

        return $delivery;
    }

    /**
     * Queue a delivery. The snapshot is taken now, not at send time.
     *
     * That is the whole of §6.2's requirement: the bytes that go out on a retry
     * — or on a replay next week — are the bytes this unit had when the event
     * happened, because otherwise a receiver cannot tell a repeat from an edit.
     *
     * The event is optional, and left out is the normal case: which event a
     * destination is owed is a fact about what that destination has already
     * received, so {@see eventFor()} works it out. A caller naming one is
     * saying something the history cannot — a deletion, or a bare `ping`.
     */
    public function queue(ContentItem $unit, Channel $channel, ?WebhookEvent $event = null): WebhookDelivery
    {
        $event ??= $this->eventFor($unit, $channel);
        $deliveryId = WebhookPayload::newDeliveryId();
        $payload = WebhookPayload::for($unit, $event, $deliveryId);
        $contentRevision = $payload['content'] ?? [];

        // The first receiver confirms the global published_at timestamp. A
        // second channel seeing that timestamp must not make unchanged content
        // look like a new revision, so it is metadata rather than identity.
        if (is_array($contentRevision)) {
            unset($contentRevision['published_at']);
        }

        $revision = hash('sha256', (string) json_encode(
            $contentRevision,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $dispatchKey = hash('sha256', implode('|', [
            $unit->getKey(),
            $channel->getKey(),
            $event->carriesBody() ? 'content.upsert' : $event->value,
            $revision,
        ]));

        $delivery = WebhookDelivery::query()->firstOrCreate(
            ['dispatch_key' => $dispatchKey],
            [
                'channel_id' => $channel->getKey(),
                'content_item_id' => $unit->getKey(),
                'delivery_id' => $deliveryId,
                'status' => DeliveryStatus::Pending->value,
                'payload_snapshot' => $payload,
            ],
        );

        if ($delivery->wasRecentlyCreated) {
            DeliverWebhookJob::dispatch($delivery->getKey())->afterCommit();
        }

        return $delivery;
    }

    /**
     * Re-send a delivery's stored snapshot under a **new** delivery id.
     *
     * New, deliberately, and the contract says so: reusing the id would make
     * the receiver dedupe it away as a repeat, which is the opposite of what a
     * replay is for. The bytes are the old ones; only the envelope is new.
     */
    public function replay(WebhookDelivery $delivery): WebhookDelivery
    {
        if ($delivery->article_schedule_id !== null) {
            $lock = Cache::lock('webhook-delivery:'.$delivery->id, self::lockSeconds());
            // Refusals are validation errors on `delivery`, not bare 409s: the
            // owner pressed "Try again" and has to be told, beside the button,
            // why nothing happened.
            if (! $lock->get()) {
                throw ValidationException::withMessages(['delivery' => 'This article is being sent right now. Wait for the result before trying again.']);
            }
            try {
                $delivery->refresh();
                if ($delivery->status === DeliveryStatus::Delivered) {
                    return $delivery;
                }
                if ($delivery->status !== DeliveryStatus::DeadLetter) {
                    throw ValidationException::withMessages(['delivery' => 'Avyo is already trying to send this article. Wait for the result before trying again.']);
                }
                // An uncertain website outcome must be reconciled with its original receipt identity.
                $refusal = app(ArticleDeliveryGuard::class)->refusal($delivery)
                    ?? ($delivery->contentItem === null ? null : app(ArticleBusinessFacts::class)->refusal($delivery->contentItem));
                if ($refusal !== null) {
                    throw ValidationException::withMessages(['delivery' => $refusal]);
                }
                // Due now, and stamped so: the row keeps its original
                // `created_at`, and StrandedDeliveries ages a pending row from
                // when it was due. Left null, a replay of a week-old delivery
                // would look abandoned the moment it was queued, and the
                // minute-by-minute sweep would dispatch it a second time.
                $delivery->forceFill(['status' => DeliveryStatus::Pending, 'next_attempt_at' => now(), 'error' => null, 'deferrals' => 0, 'sweeps' => 0])->save();
            } finally {
                $lock->release();
            }
            DeliverWebhookJob::dispatch($delivery->getKey())->afterCommit();

            return $delivery;
        }

        $deliveryId = WebhookPayload::newDeliveryId();

        $snapshot = $delivery->payload_snapshot;
        $snapshot['delivery_id'] = $deliveryId;
        $snapshot['sent_at'] = now()->toIso8601String();

        $replay = WebhookDelivery::query()->create([
            'channel_id' => $delivery->channel_id,
            'content_item_id' => $delivery->content_item_id,
            'delivery_id' => $deliveryId,
            'status' => DeliveryStatus::Pending->value,
            'payload_snapshot' => $snapshot,
        ]);

        DeliverWebhookJob::dispatch($replay->getKey())->afterCommit();

        return $replay;
    }

    /**
     * One attempt. Never throws: a delivery's outcome is a row, not an
     * exception, and the job that calls this must not be retried by the queue.
     */
    public function attempt(WebhookDelivery $delivery, ?CarbonInterface $scheduledFor = null): WebhookDelivery
    {
        $lock = Cache::lock('webhook-delivery:'.$delivery->getKey(), self::lockSeconds());

        if (! $lock->get()) {
            return $delivery->refresh();
        }

        try {
            $delivery->refresh();

            if ($this->waitingForAnotherJob($delivery, $scheduledFor)) {
                return $delivery;
            }

            $result = $this->attemptWhileLocked($delivery);
        } finally {
            $lock->release();
        }

        // Schedule only after releasing the delivery lock. The sync queue
        // executes immediately; dispatching while locked would make the next
        // attempt see the lock, do nothing, and strand the row in retrying.
        if ($result->status === DeliveryStatus::Retrying) {
            // Read back first, so the rung the job carries is the one the row
            // stores — whole seconds — and not the unrounded time in memory.
            $result->refresh();

            DeliverWebhookJob::dispatch($result->getKey(), $result->next_attempt_at)
                ->afterCommit()
                ->delay($result->next_attempt_at);

            return $result->refresh();
        }

        return $result;
    }

    /**
     * How long one attempt holds its delivery.
     *
     * Past the worker's timeout, not merely past the request's: the request
     * timeout bounds one HTTP call, while the worker's is the only thing that
     * bounds the whole attempt, and a lock that lapsed while the attempt was
     * still running would let the stranded sweep take the row out from under
     * it. See `publishing.lock_seconds`; PipelineTimeoutChainTest reads it
     * from here.
     */
    public static function lockSeconds(): int
    {
        return (int) config('publishing.lock_seconds', 100);
    }

    protected function transportName(): string
    {
        return 'webhook';
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function succeed(WebhookDelivery $delivery, int $attempt, int $latency, int $status, ?array $body): WebhookDelivery
    {
        // Asked before settling: settling is what writes `verified_at`.
        $confirms = $this->isCurrentTest($delivery);

        $this->settleDelivered($delivery, $attempt, $latency, $status);

        if ($confirms) {
            $this->confirmConnection($delivery);
        }

        $this->recordPublicUrl($delivery, $body);
        $this->markUnitPublished($delivery);

        if ($delivery->contentItem !== null) {
            app(RegisterPublishedArticle::class)->register($delivery->contentItem);
        }

        return $delivery;
    }

    /**
     * What a transport records once its test has been answered, beyond the
     * `verified_at` every transport shares. Nothing, for a plain webhook.
     */
    protected function confirmConnection(WebhookDelivery $delivery): void {}

    protected function endpoint(Channel $channel): string
    {
        return (string) ($channel->config['endpoint'] ?? '');
    }

    protected function sendRequest(WebhookDelivery $delivery, string $endpoint, string $body, int $timestamp): Response
    {
        $channel = $delivery->channel;
        $target = $this->targets->validate($endpoint);

        return Http::withHeaders([
            'Authorization' => 'Bearer '.(string) $channel->secret,
            'X-Engine-Delivery' => $delivery->delivery_id,
            'X-Engine-Event' => (string) ($delivery->payload_snapshot['event'] ?? ''),
            'X-Engine-Timestamp' => (string) $timestamp,
            'X-Engine-Signature' => WebhookSignature::compute((string) $channel->secret, $timestamp, $body),
            'X-Engine-Contract' => (string) config('publishing.contract_version', 1),
            'Content-Type' => 'application/json; charset=utf-8',
        ])->timeout((int) config('publishing.timeout', 15))->retry(0)->withoutRedirecting()
            ->withOptions($target->httpOptions())->withBody($body, 'application/json')->post($target->url);
    }

    protected function acceptsResponse(WebhookDelivery $delivery, Response $response): bool
    {
        return $response->successful() || $response->status() === 409;
    }

    /**
     * Whether this row's next attempt belongs to a different job.
     *
     * A row can have two jobs: the stranded sweep queues one while the
     * original was only stuck behind a busy queue, and whichever runs second
     * finds a retry the first has already scheduled — with a job of its own,
     * delayed to the rung's time. Sending now would take that rung at an
     * interval nobody promised, and queueing another retry would keep the
     * duplicate alive rung after rung, so the second job does neither.
     *
     * The job scheduled for the rung is recognised by the rung it carries, not
     * by the clock: Redis releases it on time, and the sync queue the suite
     * runs on releases it at once. Any other job goes by the clock, with a few
     * seconds of grace for the clocks of two machines.
     */
    private function waitingForAnotherJob(WebhookDelivery $delivery, ?CarbonInterface $scheduledFor): bool
    {
        $due = $delivery->next_attempt_at;

        if ($delivery->status !== DeliveryStatus::Retrying || $due === null) {
            return false;
        }

        if ($scheduledFor !== null && $scheduledFor->equalTo($due)) {
            return false;
        }

        return $due->isAfter(now()->addSeconds(self::DUE_GRACE_SECONDS));
    }

    /**
     * A destination that has never received this unit is owed
     * `content.published`, even if another channel made the unit globally live
     * earlier; one that has is owed `content.updated`.
     */
    private function eventFor(ContentItem $unit, Channel $channel): WebhookEvent
    {
        $hasDeliveredContent = WebhookDelivery::query()
            ->where('content_item_id', $unit->getKey())
            ->where('channel_id', $channel->getKey())
            ->where('status', DeliveryStatus::Delivered->value)
            ->whereIn('payload_snapshot->event', [
                WebhookEvent::Published->value,
                WebhookEvent::Updated->value,
            ])
            ->exists();

        return $hasDeliveredContent ? WebhookEvent::Updated : WebhookEvent::Published;
    }

    private function attemptWhileLocked(WebhookDelivery $delivery): WebhookDelivery
    {
        if ($delivery->status->isSettled()) {
            return $delivery;
        }

        if ($withdrawn = $this->refuseIfWithdrawn($delivery)) {
            return $withdrawn;
        }

        $channel = $delivery->channel;
        $endpoint = $this->endpoint($channel);

        if ($endpoint === '') {
            return $this->deadLetter($delivery, 'No address is set for this website. Add one on the Website page.');
        }

        $body = (string) json_encode($delivery->payload_snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();
        $attempt = $delivery->attempts + 1;
        $startedAt = hrtime(true);

        $this->markArticleAttemptStarted($delivery);

        try {
            $response = $this->sendRequest($delivery, $endpoint, $body, $timestamp);
        } catch (ConnectionException $e) {
            // Never reached the receiver. Always worth another go.
            return $this->scheduleRetry($delivery, $attempt, $this->elapsed($startedAt), null, $e->getMessage());
        } catch (Throwable $e) {
            return $this->deadLetter($delivery, $e->getMessage(), $attempt, $this->elapsed($startedAt));
        }

        $latency = $this->elapsed($startedAt);
        $status = $response->status();

        // 409 is the receiver saying "already have this one" — which is the
        // idempotency contract working, not a failure (§2 of the contract).
        if ($this->acceptsResponse($delivery, $response)) {
            return $this->succeed($delivery, $attempt, $latency, $status, $response->json());
        }

        if ($this->isRetryable($status)) {
            return $this->scheduleRetry($delivery, $attempt, $latency, $status, "receiver answered {$status}");
        }

        // 401, 403, 422 and friends: the request was wrong, and it will be
        // exactly as wrong in twelve hours.
        return $this->deadLetter($delivery, "receiver refused with {$status}", $attempt, $latency, $status);
    }

    private function isRetryable(int $status): bool
    {
        return $status >= 500 || in_array($status, [408, 425, 429], true);
    }

    /**
     * §3: the receiver answers with `public_url`, and the unit keeps it —
     * without it the GSC matching of phase 9 has nothing to match on.
     *
     * A receiver that does not answer with one is under-connected rather than
     * broken, so this never fails the delivery.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function recordPublicUrl(WebhookDelivery $delivery, ?array $body): void
    {
        $url = $body['public_url'] ?? null;

        if (! is_string($url) || $url === '') {
            return;
        }

        $unit = $delivery->contentItem;

        if ($unit === null) {
            return;
        }

        try {
            $unit->loadMissing('project');
            $projectOrigin = $unit->project->website_url;
            $validated = $this->targets->validate(
                $url,
                is_string($projectOrigin) && $projectOrigin !== '' ? $projectOrigin : null,
            );
        } catch (UnsafePublicUrl $e) {
            Log::warning('A receiver returned an unsafe public URL', [
                'delivery' => $delivery->delivery_id,
                'url' => $url,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $unit->forceFill(['public_url' => $validated->url])->save();
    }
}
