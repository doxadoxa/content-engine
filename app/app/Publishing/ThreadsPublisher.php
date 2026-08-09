<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Integrations\Exceptions\ThreadsCredentialRejected;
use App\Integrations\Exceptions\ThreadsRefused;
use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Integrations\Threads\ThreadsClient;
use App\Integrations\Threads\ThreadsConnection;
use App\Models\Asset;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\WebhookDelivery;
use App\Publishing\Concerns\RecordsDeliveryOutcome;
use App\Publishing\Contracts\ChannelPublisher;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use App\Support\Social\ChannelPayload;
use App\Support\Social\ChannelPayloadSegment;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Publishing to Threads: two calls per segment, and a journal between them (§9).
 *
 * The mechanics are {@see WebhookPublisher}'s — the deliveries table, the
 * backoff ladder of §6.2, dead letter, replay — because §9 is explicit that
 * "меняется транспорт, а не механика". What is different is the one thing §9
 * spends its longest paragraph on:
 *
 * > Идемпотентности от Threads нет — клиентского ключа в API не существует, а
 * > публикация двухфазная. Поэтому задание доставки пишет `container_id` и
 * > `published_id` в `channel_payload` после каждого вызова и при ретрае
 * > продолжает с первого сегмента без `published_id`.
 *
 * A webhook receiver dedupes on `X-Engine-Delivery`, so a retry there is free.
 * Here there is no key to send: `POST /{user-id}/threads` followed by
 * `POST /{user-id}/threads_publish` will happily produce a second, identical
 * root post, and nothing on the platform will say the first one exists. The
 * only record is the one this class keeps, in `content_items.channel_payload`,
 * written after **every** call rather than at the end of a segment — a worker
 * killed between the container and the publish is the normal case, not the
 * unlucky one, and it is exit criterion 4.
 *
 * **Why the journal is not `payload_snapshot`.** §6.2 snapshots the bytes on
 * the delivery so that a retry sends what was approved. That is still true of
 * the text here, and the snapshot holds it. But the *journal* cannot live on
 * the delivery row: a second delivery for the same unit — an operator pressing
 * publish again after an edit, a replay, a channel re-queued — has to see what
 * the first one already published, or it publishes the root twice. So the
 * journal is the unit's column, which is what §3 designed it to be, and the
 * snapshot is a record of what was queued.
 *
 * **How the journal write is made safe.** Two overlapping attempts doing a
 * read-modify-write of one jsonb document can lose a `published_id`, and a lost
 * `published_id` is a duplicate root post — the exact failure this class exists
 * to prevent. {@see WebhookPublisher::attempt()} takes `Cache::lock()` on the
 * delivery id, which is not enough here for two reasons: the resource being
 * mutated is the *publication*, not the delivery, and two different deliveries
 * for one unit would each take a different lock; and a cache lock is an
 * agreement between processes that share a cache, not a guarantee. So there are
 * two layers, doing different jobs:
 *
 * - a cache lock on the **content unit** ({@see lockKey()}), which stops two
 *   attempts at the same publication from running at all — the useful outcome,
 *   because concurrent attempts do not just corrupt the journal, they create
 *   duplicate containers;
 * - a row lock on the unit inside every journal write ({@see journal()}), which
 *   is the correctness guarantee. `SELECT … FOR UPDATE`, re-read, merge, write,
 *   commit — so a writer that did not observe the cache lock still serialises,
 *   and a merge never writes back a document it read before the other writer's
 *   commit. The mutation is expressed as a change to one segment rather than as
 *   a whole document, so the merge cannot carry a stale sibling either.
 *
 * The row lock alone would leave the duplicate-container hole; the cache lock
 * alone would leave a lost update whenever the cache and the database disagree.
 * Neither is redundant.
 *
 * ⚠️ Written from Meta's published documentation and **not verified against a
 * live account**, exactly like {@see ThreadsClient} and {@see ThreadsConnection}
 * and following the Ahrefs and Google adapters. Nothing in the suite reaches the
 * network: the request shapes and response fields below are the documented ones,
 * asserted, and unproven until someone runs them against a real token.
 *
 * ⚠️ One of the unverified things is not a response shape but a decision.
 * {@see containerParameters()} makes every chained segment answer the **root**
 * post rather than the segment before it, so a three-part chain is a root with
 * two direct replies. §2 says only "`reply_to_id` при создании контейнера" and
 * does not say which id, so this is an assumption about how Threads renders a
 * chain and not a requirement being followed. The alternative — each segment
 * answering its predecessor — is the shape most clients produce, and the two
 * read differently in the feed. Check it on a live account with the rest, and
 * if the reading is wrong the fix is one line here rather than a change to the
 * journal, which records ids and takes no position on the tree.
 */
class ThreadsPublisher implements ChannelPublisher
{
    use RecordsDeliveryOutcome;

    /**
     * The margin over the worst case, so a slow platform cannot expire a lease
     * that is still doing useful work.
     *
     * `WebhookPublisher` leases 45 seconds to guard one 15-second POST — a
     * threefold margin. This is the same discipline expressed as a multiplier,
     * because here the worst case depends on how many segments a post has.
     */
    private const int LOCK_MARGIN = 2;

    /** Calls outside the per-segment loop: the quota read and the permalink read. */
    private const int LOCK_OVERHEAD_CALLS = 2;

    /** The floor under a budget deferral, so a full window cannot spin. */
    private const int MINIMUM_DEFERRAL = 60;

    public function __construct(
        private readonly ThreadsClient $client,
        private readonly ThreadsConnection $connection,
        private readonly ThreadsRateLimiter $limiter,
        private readonly PublicHttpTarget $targets,
    ) {}

    public function supports(ChannelType $type): bool
    {
        return $type === ChannelType::Threads;
    }

    /**
     * A Threads ping is not a signed POST — it is proving the credential.
     *
     * The wizard's third step and the channel screen's test button are shared
     * across transports, so the question they ask has to have an answer here;
     * what changes is what "test" means. There is nothing to sign and nobody to
     * receive it, so the proof is reading the connected user back through the
     * API. It still writes a delivery row and still sets `verified_at`, because
     * both screens read those and neither should learn a second shape.
     */
    public function canPing(): bool
    {
        return true;
    }

    /**
     * Unattended publishing is a decision about a post, not about a transport.
     *
     * §5 gives four of six bands the possibility of going out without a person
     * watching, so answering no here would overrule the band table from the
     * wrong place. The governor of §4.3 and the band are where a specific post
     * is held back; this only says the transport is capable of it.
     */
    public function canAutopublish(): bool
    {
        return true;
    }

    /**
     * Queue one post for one channel.
     *
     * The dispatch key is {@see WebhookPublisher::queue()}'s shape, for the same
     * reason: pressing publish twice on unchanged content is the operator asking
     * "did that go out", and it must produce one delivery. The revision is
     * hashed over the post's *content* only — the segments' text and images and
     * the posting options — and deliberately not over the journal fields, which
     * this class writes itself and which would otherwise make every attempt look
     * like a new revision.
     */
    public function queue(ContentItem $unit, Channel $channel): WebhookDelivery
    {
        $payload = $this->payloadFor($unit);
        $deliveryId = WebhookPayload::newDeliveryId();
        $revision = $this->revisionOf($payload);

        $dispatchKey = hash('sha256', implode('|', [
            $unit->getKey(),
            $channel->getKey(),
            'threads.publish',
            $revision,
        ]));

        $delivery = WebhookDelivery::query()->firstOrCreate(
            ['dispatch_key' => $dispatchKey],
            [
                'channel_id' => $channel->getKey(),
                'content_item_id' => $unit->getKey(),
                'delivery_id' => $deliveryId,
                'status' => DeliveryStatus::Pending->value,
                'payload_snapshot' => [
                    'event' => WebhookEvent::Published->value,
                    'channel_type' => ChannelType::Threads->value,
                    'delivery_id' => $deliveryId,
                    'content_item_id' => $unit->getKey(),
                    // What was approved, for the delivery log to show. The
                    // journal on the unit is what the attempt reads.
                    'segments' => array_map(
                        static fn (ChannelPayloadSegment $segment): string => $segment->text,
                        $payload->segments,
                    ),
                    'revision' => $revision,
                ],
            ],
        );

        if ($delivery->wasRecentlyCreated) {
            DeliverWebhookJob::dispatch($delivery->getKey())
                ->onQueue((string) config('publishing.queue'))
                ->afterCommit();
        }

        return $delivery;
    }

    /**
     * Prove the credential, with no post involved.
     *
     * Reading the connected user is the cheapest call that fails for every
     * reason the operator needs to hear about: no app configured, no connection,
     * a token the platform has stopped honouring. It spends nothing from the
     * publishing budget of §2, which is counted in publish actions.
     */
    public function ping(Channel $channel, Project $project): WebhookDelivery
    {
        $deliveryId = WebhookPayload::newDeliveryId();

        $delivery = WebhookDelivery::query()->create([
            'channel_id' => $channel->getKey(),
            'content_item_id' => null,
            'delivery_id' => $deliveryId,
            'status' => DeliveryStatus::Pending->value,
            'payload_snapshot' => [
                'event' => WebhookEvent::Ping->value,
                'channel_type' => ChannelType::Threads->value,
                'delivery_id' => $deliveryId,
                'project' => $project->getKey(),
            ],
        ]);

        DeliverWebhookJob::dispatch($delivery->getKey())
            ->onQueue((string) config('publishing.queue'))
            ->afterCommit();

        return $delivery;
    }

    /**
     * Replay a two-phase publication — which can only ever mean *resume* it.
     *
     * A webhook replay re-sends the stored bytes under a new delivery id, and
     * the receiver's idempotency makes that harmless. Nothing here is harmless:
     * re-sending a published post is a second root post, the one outcome §9 and
     * exit criterion 4 exist to prevent, and it is not undoable from this side.
     *
     * So a replay is a new delivery row addressed at the same publication, and
     * the attempt behind it reads the journal like any other: it publishes the
     * segments with no `published_id` and nothing else. Three cases, all
     * honest — a publication interrupted before the root goes out as normal; one
     * interrupted mid-chain has its remaining segments finished; and one that is
     * complete settles as delivered having sent nothing, which is the true
     * answer to "replay this" and is visible as such in the delivery log.
     *
     * The row carries no `dispatch_key`: it is deliberately not the queue()
     * idempotency of "this revision has been sent", it is an operator asking
     * again.
     */
    public function replay(WebhookDelivery $delivery): WebhookDelivery
    {
        $deliveryId = WebhookPayload::newDeliveryId();

        $snapshot = $delivery->payload_snapshot;
        $snapshot['delivery_id'] = $deliveryId;
        $snapshot['replay_of'] = $delivery->delivery_id;
        $snapshot['replayed_at'] = now()->toIso8601String();

        $replay = WebhookDelivery::query()->create([
            'channel_id' => $delivery->channel_id,
            'content_item_id' => $delivery->content_item_id,
            'delivery_id' => $deliveryId,
            'status' => DeliveryStatus::Pending->value,
            'payload_snapshot' => $snapshot,
        ]);

        DeliverWebhookJob::dispatch($replay->getKey())
            ->onQueue((string) config('publishing.queue'))
            ->afterCommit();

        return $replay;
    }

    /**
     * One attempt. Never throws — a delivery's outcome is a row.
     *
     * The lock is on the publication rather than on the delivery; see the class
     * docblock for why that difference is the whole point.
     */
    public function attempt(WebhookDelivery $delivery): WebhookDelivery
    {
        $lock = Cache::lock($this->lockKey($delivery), $this->leaseSeconds($delivery));

        if (! $lock->get()) {
            // Come back, rather than disappear.
            //
            // `WebhookPublisher` returns here and is right to: its lock is on
            // the delivery, so contention means "this exact delivery is already
            // being attempted" and the duplicate is genuinely redundant. This
            // lock is on the *publication*, so contention means a **different**
            // delivery for the same unit — a re-queued edit, a replay racing a
            // first attempt — and that one still has work to do.
            //
            // Returning silently stranded it. The row stayed `Pending`, and
            // nothing in the system rescues that state: only `Retrying`
            // re-dispatches, the job has `$tries = 1` so the queue deletes it,
            // `queue()`'s `firstOrCreate` finds the existing row and dispatches
            // nothing, no sweeper looks for stale pending rows, and the panel
            // offers replay only for a dead letter. A publication that vanished
            // with no error is the opposite of §12's "возобновляется".
            return $this->deferForLock($delivery);
        }

        try {
            $result = $this->attemptWhileLocked($delivery->refresh());
        } catch (Throwable $e) {
            // Nothing below is expected to throw, and something that does after
            // a container exists must not be swallowed into a retry: it would
            // republish. Dead letter names it and stops.
            $result = $this->deadLetter($delivery, "The Threads publisher failed unexpectedly: {$e->getMessage()}");
        } finally {
            $lock->release();
        }

        // Scheduled after the lock is released, for the reason given on the
        // same line in WebhookPublisher: the sync driver runs a delayed job
        // immediately, and dispatching while locked strands the row.
        if ($result->status === DeliveryStatus::Retrying) {
            DeliverWebhookJob::dispatch($result->getKey())
                ->onQueue((string) config('publishing.queue'))
                ->afterCommit()
                ->delay($result->next_attempt_at);

            return $result->refresh();
        }

        return $result;
    }

    // -------------------------------------------------------------- the outcome

    protected function transportName(): string
    {
        return 'Threads';
    }

    // ------------------------------------------------------------- the attempt

    private function attemptWhileLocked(WebhookDelivery $delivery): WebhookDelivery
    {
        if ($delivery->status->isSettled()) {
            return $delivery;
        }

        if ($withdrawn = $this->refuseIfWithdrawn($delivery)) {
            return $withdrawn;
        }

        if (! $this->connection->isConfigured()) {
            return $this->deadLetter($delivery, 'This installation has no Threads app configured. Set THREADS_APP_ID and THREADS_APP_SECRET, then connect the account.');
        }

        $integration = $this->connection->for();

        if ($integration === null) {
            return $this->deadLetter($delivery, 'No Threads account is connected to this project. Connect one before publishing.');
        }

        $token = $this->connection->accessToken($integration);
        $userId = $this->connection->userId($integration);

        if ($token === null) {
            return $this->deadLetter($delivery, trim(
                'Threads is no longer honouring this connection. Reconnect the account to publish again. '
                .(string) $integration->failure_reason
            ));
        }

        if ($userId === null) {
            return $this->deadLetter($delivery, 'The Threads connection does not know which account it is for. Reconnect the account to publish again.');
        }

        return ($delivery->payload_snapshot['event'] ?? null) === WebhookEvent::Ping->value
            ? $this->attemptPing($delivery, $userId, $token, $integration)
            : $this->attemptPublish($delivery, $userId, $token, $integration);
    }

    /** Reading the connected user back is the whole of a Threads ping. */
    private function attemptPing(
        WebhookDelivery $delivery,
        string $userId,
        string $token,
        ProjectIntegration $integration,
    ): WebhookDelivery {
        $attempt = $delivery->attempts + 1;
        $startedAt = hrtime(true);

        try {
            $this->client->get($userId, ['fields' => 'id,username'], $token);
        } catch (ThreadsCredentialRejected $e) {
            return $this->credentialRejected($delivery, $integration, $e, $attempt, $this->elapsed($startedAt));
        } catch (ThreadsUnavailable $e) {
            return $this->scheduleRetry($delivery, $attempt, $this->elapsed($startedAt), null, $e->getMessage());
        } catch (ThreadsRefused $e) {
            return $this->deadLetter($delivery, "Threads refused the connection test: {$e->getMessage()}", $attempt, $this->elapsed($startedAt), $e->status);
        }

        return $this->settleDelivered($delivery, $attempt, $this->elapsed($startedAt), 200);
    }

    /**
     * The chain, segment by segment, resuming wherever the journal says.
     *
     * Every refusal below is classified before a single call goes out, because
     * a post over 500 characters (§2) is refused identically in twelve hours and
     * walking the ladder over it spends the budget to learn nothing.
     */
    private function attemptPublish(
        WebhookDelivery $delivery,
        string $userId,
        string $token,
        ProjectIntegration $integration,
    ): WebhookDelivery {
        $unit = $delivery->contentItem;

        if ($unit === null) {
            return $this->deadLetter($delivery, 'The delivery has no post to publish.');
        }

        $channel = $delivery->channel;

        try {
            $payload = ChannelPayload::fromArray(is_array($unit->channel_payload) ? $unit->channel_payload : []);
        } catch (InvalidArgumentException $e) {
            return $this->deadLetter($delivery, "The post's channel payload cannot be read: {$e->getMessage()}");
        }

        $refusal = $this->refusalIn($payload);

        if ($refusal !== null) {
            return $this->deadLetter($delivery, $refusal);
        }

        $images = $this->imagesIn($payload, $unit);

        if (is_string($images)) {
            return $this->deadLetter($delivery, $images);
        }

        // Asked of the payload rather than re-derived here. §9's resume rule is
        // one question, and two spellings of it — one unit-tested on
        // {@see ChannelPayload} and one shipped in this loop — is how the two
        // come to disagree about a journal with a gap in it.
        $pending = $payload->unpublishedSegments();

        $attempt = $delivery->attempts + 1;
        $startedAt = hrtime(true);

        if ($pending === []) {
            // Everything the journal knows about is already on the platform.
            // The honest outcome of an attempt with nothing to do, and the one
            // that keeps a replay of a finished publication from repeating it.
            $this->finish($delivery, $payload, $unit, $userId, $token);

            return $this->settleDelivered($delivery, $attempt, $this->elapsed($startedAt), 200);
        }

        // Two calls for a segment with no container, one for a segment that
        // already has one. §2 counts actions, not posts.
        $cost = array_sum(array_map(
            static fn (ChannelPayloadSegment $segment): int => $segment->containerId === null ? 2 : 1,
            $pending,
        ));

        if (! $this->limiter->allows($channel, $cost, $userId, $token)) {
            return $this->deferForBudget($delivery, $channel, $cost, $userId, $token);
        }

        $rootId = $payload->rootPostId();

        foreach ($pending as $segment) {
            try {
                $containerId = $segment->containerId;

                if ($containerId === null) {
                    $created = $this->client->post(
                        "{$userId}/threads",
                        $this->containerParameters($segment, $payload, $images[$segment->position] ?? null, $rootId),
                        $token,
                    );
                    $containerId = $this->idIn($created);

                    if ($containerId === null) {
                        // An orphan container costs the account an action and
                        // expires on its own. Nothing was published, so this is
                        // safe to try again.
                        return $this->scheduleRetry(
                            $delivery,
                            $attempt,
                            $this->elapsed($startedAt),
                            null,
                            'Threads accepted a container without answering with its id.',
                        );
                    }

                    // Before anything else, §9. A container id lost here is a
                    // second container on the retry; a crash one line later is
                    // the case this write exists for.
                    $payload = $this->journal($unit, fn (ChannelPayload $current): ChannelPayload => $this->withSegment(
                        $current,
                        $segment->position,
                        containerId: $containerId,
                    ));

                    $this->limiter->spend($channel, 1);
                }

                try {
                    $published = $this->client->post("{$userId}/threads_publish", [
                        'creation_id' => $containerId,
                    ], $token);
                } catch (ThreadsUnavailable $e) {
                    if ($e->answered) {
                        // A 429 or a 5xx is the platform declining. It said so,
                        // which means it did not publish, so the container is
                        // still unspent and another go is free.
                        throw $e;
                    }

                    // The one failure a journal structurally cannot see.
                    //
                    // The request left; nothing came back. Either the post is
                    // live and we will never learn its id from this call, or
                    // nothing happened — and there is no way to ask. Retrying
                    // re-presents a `creation_id` that may already be consumed,
                    // and Threads has no client key that would make that safe.
                    //
                    // The same answer as the 200-with-no-id case below, for the
                    // same reason. Anything else would have this class arguing
                    // with itself: that branch stops because "retrying would
                    // present the same creation id again", and this one has
                    // exactly that property and occurs far more often.
                    //
                    // Note the asymmetry. A lost connection while *creating* a
                    // container is still retried, above: nothing can be live
                    // yet, and an orphan container expires on its own.
                    return $this->deadLetter(
                        $delivery,
                        "Threads did not answer the publish for segment {$segment->position}: {$e->getMessage()} "
                        .'The post may be live. Check the account before publishing it again — retrying would '
                        .'present the same container a second time, and the platform offers no way to ask.',
                        $attempt,
                        $this->elapsed($startedAt),
                    );
                }

                $publishedId = $this->idIn($published);

                if ($publishedId === null) {
                    // The post may well be live. Retrying would present the same
                    // creation id again, so this stops and says so — an operator
                    // looking at the account is the only safe next step.
                    return $this->deadLetter(
                        $delivery,
                        'Threads accepted the publish without answering with a post id. The post may be live; check the account before publishing it again.',
                        $attempt,
                        $this->elapsed($startedAt),
                    );
                }

                $payload = $this->journal($unit, fn (ChannelPayload $current): ChannelPayload => $this->withSegment(
                    $current,
                    $segment->position,
                    containerId: $containerId,
                    publishedId: $publishedId,
                ));

                $this->limiter->spend($channel, 1);

                $rootId ??= $publishedId;
            } catch (ThreadsCredentialRejected $e) {
                return $this->credentialRejected($delivery, $integration, $e, $attempt, $this->elapsed($startedAt));
            } catch (ThreadsUnavailable $e) {
                return $this->scheduleRetry($delivery, $attempt, $this->elapsed($startedAt), null, $e->getMessage());
            } catch (ThreadsRefused $e) {
                return $this->deadLetter($delivery, "Threads refused the post: {$e->getMessage()}", $attempt, $this->elapsed($startedAt), $e->status);
            }
        }

        $this->finish($delivery, $payload, $unit, $userId, $token);

        return $this->settleDelivered($delivery, $attempt, $this->elapsed($startedAt), 200);
    }

    /**
     * Every segment's image address, or the reason the post cannot go out.
     *
     * Resolved before the first call, with the rest of the terminal refusals,
     * because an asset that is gone and an image only this machine can reach
     * are facts about the post: a retry finds them unchanged, and a chain that
     * discovered the second one at its third segment would have published the
     * first two.
     *
     * @return array<int, string>|string
     */
    private function imagesIn(ChannelPayload $payload, ContentItem $unit): array|string
    {
        $images = [];

        foreach ($payload->segments as $segment) {
            if ($segment->assetId === null || $segment->isPublished()) {
                continue;
            }

            $asset = Asset::query()
                ->whereKey($segment->assetId)
                ->where('content_item_id', $unit->getKey())
                ->first();

            if ($asset === null) {
                return "Segment {$segment->position} names an image that no longer exists.";
            }

            try {
                // Threads fetches the file itself, so an address only this
                // machine can reach fails on their side with a message the
                // operator never sees.
                $images[$segment->position] = $this->targets->validate($asset->url())->url;
            } catch (UnsafePublicUrl $e) {
                return "Segment {$segment->position}'s image is not reachable from the public internet: {$e->getMessage()}";
            }
        }

        return $images;
    }

    /**
     * The container request for one segment.
     *
     * @return array<string, string>
     */
    private function containerParameters(
        ChannelPayloadSegment $segment,
        ChannelPayload $payload,
        ?string $imageUrl,
        ?string $rootId,
    ): array {
        $parameters = ['media_type' => 'TEXT', 'text' => $segment->text];

        if ($imageUrl !== null) {
            // §2: an image outperforms text by about 60%, so this is the shape
            // a post is normally in rather than a variant.
            $parameters['media_type'] = 'IMAGE';
            $parameters['image_url'] = $imageUrl;
        }

        if ($rootId !== null) {
            // The root rather than the previous segment — an assumption about
            // how a chain reads, not something §2 states. See the ⚠️ note in
            // the class docblock, and check it with the other unverified
            // shapes on a live account.
            $parameters['reply_to_id'] = $rootId;
        }

        if ($segment->position === 0 && $payload->replyControl !== null) {
            // §3 stores it on the payload and only the root carries it: who may
            // answer is a property of the conversation, not of each post in it.
            $parameters['reply_control'] = $payload->replyControl;
        }

        return $parameters;
    }

    /**
     * Everything that happens once the platform has the whole chain.
     *
     * The permalink first, because the operator screen and §6's state snapshot
     * both want somewhere to point, and a `GET` costs nothing from the publish
     * budget of §2 — that budget counts publish actions. Best effort throughout:
     * a post that is live and has no stored permalink is under-recorded, not
     * failed, and failing the delivery over it would invite a retry that
     * republishes.
     */
    private function finish(
        WebhookDelivery $delivery,
        ChannelPayload $payload,
        ContentItem $unit,
        string $userId,
        string $token,
    ): void {
        $rootId = $payload->rootPostId();

        if ($rootId === null) {
            return;
        }

        if ($payload->permalink === null) {
            $this->recordPermalink($unit, $rootId, $token);
        }

        $this->markUnitPublished($delivery);
    }

    private function recordPermalink(ContentItem $unit, string $rootId, string $token): void
    {
        try {
            $answer = $this->client->get($rootId, ['fields' => 'permalink'], $token);
        } catch (Throwable $e) {
            Log::info('A Threads post was published without a permalink', [
                'unit' => $unit->getKey(),
                'post' => $rootId,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $permalink = $answer['permalink'] ?? null;

        if (! is_string($permalink) || $permalink === '') {
            return;
        }

        $this->journal($unit, static fn (ChannelPayload $current): ChannelPayload => new ChannelPayload(
            segments: $current->segments,
            linkAttachment: $current->linkAttachment,
            replyControl: $current->replyControl,
            angle: $current->angle,
            permalink: $permalink,
            publishedRootId: $current->publishedRootId,
        ));

        // §3's `public_url` is what the feedback loop matches on, and for a post
        // the permalink is it.
        if ($unit->public_url === null) {
            $unit->forceFill(['public_url' => $permalink])->save();
        }
    }

    // ------------------------------------------------------------- the journal

    /**
     * One journal write: lock the row, re-read, merge, save, commit.
     *
     * The re-read is the point. A closure that mutates the *current* document
     * rather than the one the attempt started with is what makes a concurrent
     * writer's `published_id` survive, and a `published_id` that does not
     * survive is a root post published twice.
     *
     * @param  Closure(ChannelPayload): ChannelPayload  $mutate
     */
    private function journal(ContentItem $unit, Closure $mutate): ChannelPayload
    {
        return DB::transaction(function () use ($unit, $mutate): ChannelPayload {
            $locked = ContentItem::query()->whereKey($unit->getKey())->lockForUpdate()->first();
            $row = $locked ?? $unit;

            $current = ChannelPayload::fromArray(is_array($row->channel_payload) ? $row->channel_payload : []);
            $next = $mutate($current);
            $stored = $next->toArray();

            $row->forceFill(['channel_payload' => $stored])->save();

            // The caller keeps working with its own instance; leaving it holding
            // the pre-write document would make the next read-modify-write in
            // this attempt start from a stale one.
            //
            // Synced, not merely set. `setAttribute()` alone leaves the column
            // **dirty** on the caller's model, and Eloquent flushes every dirty
            // attribute on save — so the two later saves in `finish()` (the
            // `public_url` write, and `ContentItem::transitionTo()` reached
            // through `markUnitPublished()`) would each rewrite the whole
            // journal column from this in-memory copy, outside this transaction
            // and outside the row lock. Both of those saves happen *after* a
            // thirty-second permalink read, which is a wide enough window for a
            // concurrent journal commit to land inside and be silently undone.
            // A `published_id` lost that way is a segment published twice, and
            // a lost `published_root_id` is a second root post — the exact
            // outcome the lock above exists to prevent, defeated by an
            // attribute nobody meant to keep dirty.
            $unit->setAttribute('channel_payload', $stored);
            $unit->syncOriginalAttribute('channel_payload');

            return $next;
        });
    }

    /**
     * The document with one segment's ids filled in, and the root recorded.
     *
     * Segment-scoped deliberately: a mutation expressed as "this segment now has
     * this id" cannot carry a stale sibling back into the document the way a
     * whole-document write can.
     */
    private function withSegment(
        ChannelPayload $payload,
        int $position,
        ?string $containerId = null,
        ?string $publishedId = null,
    ): ChannelPayload {
        $segments = [];
        $rootId = $payload->publishedRootId;

        foreach ($payload->segments as $segment) {
            if ($segment->position !== $position) {
                $segments[] = $segment;

                continue;
            }

            $segments[] = new ChannelPayloadSegment(
                position: $segment->position,
                text: $segment->text,
                assetId: $segment->assetId,
                containerId: $containerId ?? $segment->containerId,
                publishedId: $publishedId ?? $segment->publishedId,
            );

            if ($position === 0 && $publishedId !== null) {
                $rootId ??= $publishedId;
            }
        }

        return new ChannelPayload(
            segments: $segments,
            linkAttachment: $payload->linkAttachment,
            replyControl: $payload->replyControl,
            angle: $payload->angle,
            permalink: $payload->permalink,
            publishedRootId: $rootId,
        );
    }

    // -------------------------------------------------------------- the payload

    /**
     * The unit's canonical view of itself as a post, created if it has none.
     *
     * §3 makes `channel_payload` the adapter's input as well as its journal, and
     * 12.5's `social_draft` is what normally writes it. A unit arriving here
     * without one is not an error — a post written by hand, or a fixture — so
     * one segment is derived from the body and stored, which also means the
     * journal exists before the first call rather than being created by it.
     *
     * One segment, never a split: §2 says the default is one post and one
     * thought, and a chain is an exception a step has to justify. Cutting a body
     * into 500-character pieces here would manufacture exactly the thread-essay
     * §2 says does not work.
     */
    private function payloadFor(ContentItem $unit): ChannelPayload
    {
        $stored = $unit->channel_payload;

        if (is_array($stored) && $stored !== []) {
            try {
                $payload = ChannelPayload::fromArray($stored);

                if ($payload->segments !== []) {
                    return $payload;
                }
            } catch (InvalidArgumentException) {
                // Unreadable is not overwritable: the attempt dead-letters on it
                // with the reason, where replacing it would destroy a journal.
                return new ChannelPayload;
            }
        }

        $text = trim((string) ($unit->body_markdown ?? $unit->summary ?? $unit->title));

        $payload = new ChannelPayload(
            segments: [new ChannelPayloadSegment(position: 0, text: $text)],
        );

        $unit->forceFill(['channel_payload' => $payload->toArray()])->save();

        return $payload;
    }

    /**
     * What makes two queue() calls the same publication.
     *
     * Content only. `container_id`, `published_id`, `published_root_id` and the
     * permalink are this class's own writing, and hashing them would give every
     * attempt a new revision and every retry a new delivery — which is precisely
     * the duplicate root post the journal exists to prevent, arriving through
     * the dispatch key instead.
     */
    private function revisionOf(ChannelPayload $payload): string
    {
        return hash('sha256', (string) json_encode([
            'segments' => array_map(
                static fn (ChannelPayloadSegment $segment): array => [
                    'text' => $segment->text,
                    'asset_id' => $segment->assetId,
                ],
                $payload->segments,
            ),
            'link_attachment' => $payload->linkAttachment,
            'reply_control' => $payload->replyControl,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Why this post cannot be published at all, or null.
     *
     * Everything here is terminal by construction: the platform's text limit and
     * the chain length of §2 are facts about the post, and a ladder walked over
     * them ends where it started having spent five attempts.
     */
    private function refusalIn(ChannelPayload $payload): ?string
    {
        if ($payload->segments === []) {
            return 'The post has no text to publish.';
        }

        $limit = (int) config('social.threads.text_limit', 500);
        $maximum = (int) config('social.threads.max_segments', 3);

        if (count($payload->segments) > $maximum) {
            return 'The post is '.count($payload->segments)." segments long; Threads chains are capped at {$maximum} here.";
        }

        foreach ($payload->segments as $segment) {
            $length = mb_strlen($segment->text);

            if ($segment->text === '') {
                return "Segment {$segment->position} is empty.";
            }

            if ($length > $limit) {
                return "Segment {$segment->position} is {$length} characters; Threads refuses anything over {$limit}.";
            }
        }

        return null;
    }

    /**
     * The window is full. Retry when it frees, and do not spend the ladder.
     *
     * §9 asks for the budget to be honoured, not for the post to be thrown away:
     * a full window is a good post at a bad moment, so `attempts` is left alone
     * — the ladder counts failures and this is not one — and the retry is timed
     * from the sliding window rather than from the next rung, which would wake
     * the job in a minute to be told the same thing.
     *
     * A floor under the delay, because a limiter that answered "now" would spin.
     *
     * The waiting is bounded all the same, in `webhook_deliveries.deferrals` — see
     * {@see RecordsDeliveryOutcome::defer()}. §2's budget is per *user*, and the
     * limiter consults the platform's own `quota_usage` precisely because
     * another client can be publishing as the same account. A `quota_usage`
     * pinned at the ceiling is therefore a real state and not a bug, and this
     * delivery would re-dispatch against it forever: `attempts` never moves, so
     * the ladder never ends it, and a row that is permanently `Retrying` is
     * invisible — the panel offers replay for a dead letter and nothing at all
     * for a post that has been ten minutes from going out since Tuesday. So the
     * deferrals are counted, and when they run out the row dead-letters with a
     * reason that says nothing was sent, which is the opposite of a refusal.
     */
    private function deferForBudget(
        WebhookDelivery $delivery,
        Channel $channel,
        int $cost,
        string $userId,
        string $token,
    ): WebhookDelivery {
        $ceiling = $this->limiter->ceiling($channel, $userId, $token);
        $availableAt = $this->limiter->availableAt($channel, $cost, $userId, $token);
        $earliest = now()->addSeconds(self::MINIMUM_DEFERRAL);

        return $this->defer(
            $delivery,
            $availableAt->greaterThan($earliest) ? $availableAt : $earliest,
            "The Threads publishing budget for this account is spent ({$ceiling} actions per window). "
            .'The post goes out when the window frees.',
            'The Threads publishing budget for this account was full every time this post was due '
            ."({$ceiling} actions per window). Nothing was sent and the post was never refused — check "
            .'whether something else is publishing as this account, then queue it again.',
        );
    }

    /**
     * Another publication holds this unit. Come back shortly.
     *
     * Off the backoff ladder deliberately — `attempts` is untouched, because
     * waiting for a lock is not a failed attempt and must not spend one of the
     * five the contract promises. The delay is short: whatever holds the lock is
     * publishing right now, and its own lease bounds how long that can be.
     *
     * Bounded by the same deferral count as the budget wait, and for the same
     * reason: a lock that is never released is a delivery that never ends.
     */
    private function deferForLock(WebhookDelivery $delivery): WebhookDelivery
    {
        $deferred = $this->defer(
            $delivery,
            now()->addSeconds(self::MINIMUM_DEFERRAL),
            'Another publication for this unit is in flight; this delivery waits for it to finish.',
            'Another publication for this unit held it every time this delivery came round. Nothing was '
            .'sent — check whether that other delivery finished the post before publishing it again.',
        );

        if ($deferred->status !== DeliveryStatus::Retrying) {
            return $deferred;
        }

        DeliverWebhookJob::dispatch($deferred->getKey())
            ->onQueue((string) config('publishing.queue'))
            ->afterCommit()
            ->delay($deferred->next_attempt_at);

        return $deferred;
    }

    /** The credential is finished, and only a human reconnecting fixes it. */
    private function credentialRejected(
        WebhookDelivery $delivery,
        ProjectIntegration $integration,
        ThreadsCredentialRejected $e,
        int $attempt,
        int $latency,
    ): WebhookDelivery {
        $integration->markBroken("Threads rejected the connection: {$e->getMessage()} Reconnect to grant access again.");

        return $this->deadLetter(
            $delivery,
            "Threads rejected the connection: {$e->getMessage()} Reconnect the Threads account to publish again.",
            $attempt,
            $latency,
            401,
        );
    }

    /**
     * How long this publication's lease has to be.
     *
     * Derived rather than hardcoded, because the fixed 180 seconds it replaces
     * was shorter than the work. A three-segment chain makes two calls per
     * segment plus a quota read and a permalink read: eight calls at the
     * client's own 30-second timeout is 240 seconds before DNS resolution and
     * database work are counted, so the lease expired mid-publish and a second
     * worker could take a fresh lock over a journal still being written. That
     * is a second container for the in-flight segment, and if that segment is
     * the root, a second root post.
     *
     * Read from the same config the guard policy reads, so raising
     * `social.threads.max_segments` cannot silently reintroduce the gap.
     */
    private function leaseSeconds(WebhookDelivery $delivery): int
    {
        $segments = max(1, (int) config('social.threads.max_segments', 3));
        $calls = ($segments * 2) + self::LOCK_OVERHEAD_CALLS;

        return $calls * ThreadsClient::TIMEOUT * self::LOCK_MARGIN;
    }

    /**
     * The lock names the publication, not the delivery. See the class docblock.
     *
     * A ping has no unit, and two pings of one channel racing is a wasted read
     * rather than a duplicate post, so those fall back to the delivery id.
     */
    private function lockKey(WebhookDelivery $delivery): string
    {
        return $delivery->content_item_id !== null
            ? 'threads-publication:'.$delivery->content_item_id
            : 'threads-delivery:'.$delivery->getKey();
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function idIn(array $answer): ?string
    {
        $id = $answer['id'] ?? null;

        if (is_string($id) && $id !== '') {
            return $id;
        }

        return is_int($id) ? (string) $id : null;
    }
}
