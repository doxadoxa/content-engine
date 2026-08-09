<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Enums\ChannelType;
use App\Enums\InteractionState;
use App\Http\Controllers\ThreadsWebhookController;
use App\Integrations\Exceptions\ThreadsCredentialRejected;
use App\Integrations\Exceptions\ThreadsRefused;
use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Integrations\Threads\ThreadsClient;
use App\Integrations\Threads\ThreadsConnection;
use App\Integrations\Threads\ThreadsSelfAuthor;
use App\Models\Channel;
use App\Models\Interaction;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Social\Jobs\DraftInteractionReplyJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The replies the webhook may have missed (§4.1).
 *
 * §4.1 lists "вебхуки о новых ответах" as an intake, and the webhook is already
 * built: {@see ThreadsWebhookController} writes an {@see Interaction} the
 * moment Meta pushes an event, and dispatches the drafting job. This step is
 * not that. It is the reconciliation pass behind it, because a subscription
 * that has been down for two hours, or a delivery Meta gave up on, leaves a
 * conversation nobody will ever be told about — and §4.2 measures this contour
 * in minutes.
 *
 * **It must not duplicate what the webhook wrote, and the way it does not is
 * the database.** `interactions` carries `unique (channel_id, external_id)`
 * precisely because "вебхук и опрос одного треда приносят один и тот же ответ,
 * и §4.1 гоняет оба". So the write here is `insertOrIgnore` — `ON CONFLICT DO
 * NOTHING` — exactly as in the receiver, and for the reason the receiver spells
 * out: a unique violation raised inside a Postgres transaction aborts the whole
 * transaction, so catching one poisons every query after it. Reading first and
 * then inserting would be the race rather than the fix.
 *
 * The drafting job is dispatched only for rows this pass actually created. A
 * conversation the webhook already delivered already has one; dispatching a
 * second would put two drafts on one reply an hour apart.
 *
 * **And not our own replies.** The endpoint reads a thread, and half of a
 * thread is the account's own answers. Reconciled they become conversations the
 * engine then drafts replies to — see {@see ThreadsSelfAuthor}.
 *
 * ⚠️ **`GET /{user-id}/replies` is a documented guess.** Meta's reply
 * management docs describe reading replies to one's own posts, and the shape
 * below is the ordinary Graph `{"data": [...]}`. Nothing here has seen a live
 * response. A wrong endpoint costs the reconciliation pass and nothing else —
 * the webhook is the primary path and it is unaffected.
 */
class FetchMentions extends AbstractStep
{
    /**
     * How many replies one pass reads.
     *
     * This is a safety net over a webhook, not a backfill. A gap wider than
     * this is an outage somebody should be told about rather than something an
     * hourly job quietly catches up on.
     */
    private const int LIMIT = 50;

    private const string FIELDS = 'id,text,username,permalink,timestamp,replied_to,root_post';

    public function __construct(
        private readonly ThreadsClient $client,
        private readonly ThreadsConnection $connection,
    ) {}

    public static function key(): string
    {
        return 'fetch_mentions';
    }

    public function handle(StepContext $context): StepResult
    {
        $integration = $this->connection->for($context->project);

        if ($integration === null) {
            return StepResult::skip('This project has no Threads connection.');
        }

        $token = $this->connection->accessToken($integration);
        $userId = $this->connection->userId($integration);

        if ($token === null || $userId === null) {
            return StepResult::skip('This project has no usable Threads credential to read replies with.');
        }

        $channel = Channel::query()
            ->where('type', ChannelType::Threads->value)
            ->orderBy('created_at')
            ->first();

        if ($channel === null) {
            // `interactions.channel_id` is not nullable and the conversation is
            // unanswerable without the channel that reached it — the same
            // conclusion the receiver comes to for the same reason.
            return StepResult::skip('This project has a Threads credential but no Threads channel to file replies under.');
        }

        $replies = $this->read($userId, $token);

        $recovered = 0;
        $rows = [];

        $ours = 0;

        foreach ($replies as $reply) {
            $externalId = $this->text($reply, 'id');

            if ($externalId === null) {
                continue;
            }

            if (ThreadsSelfAuthor::wrote($integration, $reply)) {
                // `GET /{user-id}/replies` reads the thread, and half of a
                // thread is us: every answer the operator sent is in this
                // response. Reconciled it becomes a `new` conversation and a
                // drafting job — the engine replying to itself, which is the
                // one loop in §4.2 that costs money on every turn. Not counted
                // as recovered either; nothing was missed.
                $ours++;

                continue;
            }

            $recovered += $this->reconcile($channel, $externalId, $reply);
            $rows[] = $reply;
        }

        $context->remember('social_listen.own_replies_skipped', $ours);

        if ($recovered > 0) {
            // Worth a line. On a healthy subscription this is zero every hour,
            // so a number here is the only evidence anyone gets that Meta is
            // dropping deliveries.
            Log::notice('The listening run found replies the Threads webhook never delivered', [
                'project' => $context->project->slug,
                'recovered' => $recovered,
            ]);
        }

        $context->remember('social_listen.recovered_replies', $recovered);

        return StepResult::success(new MentionHarvestPayload($rows, $recovered));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $userId, string $token): array
    {
        try {
            $answer = $this->client->get(
                "{$userId}/replies",
                ['fields' => self::FIELDS, 'limit' => self::LIMIT],
                $token,
            );
        } catch (ThreadsUnavailable $e) {
            throw new RetryableStepFailure("Threads did not answer the reply reconciliation: {$e->getMessage()}", previous: $e);
        } catch (ThreadsCredentialRejected $e) {
            throw new TerminalStepFailure("Threads has stopped honouring this project's connection: {$e->getMessage()}", previous: $e);
        } catch (ThreadsRefused $e) {
            // ⚠️ The most likely shape of "the endpoint above is not what the
            // platform calls this". The webhook is the primary path, so a
            // refusal here degrades the safety net rather than the contour, and
            // failing the run over it would take the search and the feeds down
            // with it.
            Log::warning('Threads refused the reply reconciliation read', ['reason' => $e->getMessage()]);

            return [];
        }

        $data = $answer['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        $rows = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Write the conversation if nobody has, and return whether we did.
     *
     * @param  array<string, mixed>  $reply
     */
    private function reconcile(Channel $channel, string $externalId, array $reply): int
    {
        $now = CarbonImmutable::now();
        $id = (string) Str::ulid();

        $written = Interaction::query()->insertOrIgnore([
            'id' => $id,
            'project_id' => $channel->project_id,
            'channel_id' => $channel->getKey(),
            'external_id' => $externalId,
            'parent_external_id' => $this->nestedId($reply, 'replied_to'),
            'root_external_id' => $this->nestedId($reply, 'root_post'),
            'author' => $this->text($reply, 'username') ?? 'unknown',
            'author_handle' => $this->authorHandle($reply),
            'text' => $this->text($reply, 'text') ?? '',
            'permalink' => $this->text($reply, 'permalink'),
            'received_at' => $this->receivedAt($reply) ?? $now,
            'state' => InteractionState::New->value,
            'raw' => json_encode($reply),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($written === 0) {
            // The webhook already has it. Not a duplicate avoided by luck —
            // `ON CONFLICT DO NOTHING` against the unique index is what decides
            // it, so two workers racing on the same reply reach the same answer.
            return 0;
        }

        DraftInteractionReplyJob::dispatch($id);

        return 1;
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    private function text(array $reply, string $key): ?string
    {
        $value = $reply[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    private function authorHandle(array $reply): ?string
    {
        $username = $this->text($reply, 'username');

        return $username === null ? null : '@'.ltrim($username, '@');
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    private function nestedId(array $reply, string $key): ?string
    {
        $nested = $reply[$key] ?? null;

        if (is_string($nested) && $nested !== '') {
            return $nested;
        }

        if (! is_array($nested)) {
            return null;
        }

        /** @var array<string, mixed> $nested */
        return $this->text($nested, 'id');
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    private function receivedAt(array $reply): ?CarbonImmutable
    {
        $timestamp = $reply['timestamp'] ?? null;

        if (is_int($timestamp)) {
            return CarbonImmutable::createFromTimestamp($timestamp)->utc();
        }

        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
