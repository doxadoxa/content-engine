<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ChannelType;
use App\Enums\IntegrationProvider;
use App\Enums\InteractionState;
use App\Http\Middleware\VerifyThreadsSignature;
use App\Integrations\Threads\ThreadsSelfAuthor;
use App\Models\Channel;
use App\Models\Interaction;
use App\Models\ProjectIntegration;
use App\Social\Jobs\DraftInteractionReplyJob;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The inbound edge of §4.1: Meta pushing us a new reply.
 *
 * Two routes with almost nothing in common. The `GET` is the subscription
 * handshake and carries no body, so it is not behind
 * {@see VerifyThreadsSignature} — there would be nothing to verify, and putting
 * it there would make the endpoint impossible to subscribe. The `POST` is
 * behind it, and by the time anything here runs the signature is settled.
 *
 * **Persist and return.** Nothing slow happens in the request. Meta retries
 * anything non-2xx or slow, so a model call inline does not make the reply
 * faster — it makes the same event arrive three times. §4.2 wants minutes, and
 * minutes are bought by dispatching {@see DraftInteractionReplyJob} rather than
 * by doing the work here.
 *
 * **Idempotency, and why it has to live in this class.**
 * {@see VerifyThreadsSignature} explains that Meta's signature carries no
 * timestamp and therefore gives no replay protection. That is not a gap the
 * middleware can close, and it does not need closing there: Meta redelivers on
 * purpose, so the endpoint has to be idempotent whether or not anyone replays
 * it. The key is the platform's own id for the reply, which `interactions`
 * already makes unique per channel, and the write is an `insertOrIgnore` —
 * never a caught unique violation. The receiver package's own controller spells
 * out why: a statement that raises inside a Postgres transaction aborts the
 * whole transaction, so catching the violation poisons every query after it.
 *
 * **Our own replies are dropped before anything is written.** Meta pushes back
 * what the account itself posts, and a stored self-authored reply is a `new`
 * conversation, which is a drafting job, which is an answer the operator sends,
 * which arrives here again. See {@see ThreadsSelfAuthor}.
 *
 * **An event for a user nobody connected is answered 200 and dropped.** A 500
 * or a 404 makes Meta retry it, and it will never resolve — the account is not
 * ours. The only thing that stops the retries is telling the truth about
 * delivery while doing nothing with the content.
 *
 * ⚠️ Written from Meta's published documentation and **not verified against
 * live webhook traffic**. The payload shape below — `entry[].changes[].value` —
 * is the Graph webhook convention; the flat `values[]` form is accepted too
 * because Threads' own examples show it, and guessing wrong in the tolerant
 * direction costs a few lines and guessing wrong in the strict direction drops
 * every event on the floor.
 */
class ThreadsWebhookController extends Controller
{
    /**
     * Meta's subscription handshake.
     *
     * Echoes `hub.challenge` as a bare string — not JSON, not wrapped. Meta
     * compares the body to the challenge it sent and refuses the subscription
     * on any difference, quotes included.
     */
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.threads.webhook_verify_token', '');

        if ($expected === '') {
            // The same answer the signature middleware gives an unconfigured
            // installation, and for the same reason: an endpoint that completes
            // the handshake without a token configured is an endpoint anybody
            // who finds the URL can subscribe.
            return response('Threads webhooks are not configured on this installation.', 503)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $provided = $this->hub($request, 'verify_token');
        $challenge = $this->hub($request, 'challenge');

        // `hash_equals` on a value from the query string, as everywhere else a
        // secret is compared here. The mode is checked with `===` because it is
        // not a secret and there is nothing to leak by returning early.
        if ($this->hub($request, 'mode') !== 'subscribe'
            || ! hash_equals($expected, $provided)
            || $challenge === '') {
            return response('Refused.', 403)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * One delivery, which may carry several events.
     *
     * Always 200 once the signature passed. Every reason an individual event
     * cannot be stored — an unknown account, a project with no Threads channel,
     * a payload without an id — is a reason Meta retrying would not fix, and
     * §4.1 would rather log it than be retried forever.
     */
    public function receive(Request $request): JsonResponse
    {
        $accepted = 0;

        foreach ($this->events($request) as $event) {
            $accepted += $this->accept($event['user_id'], $event['value']);
        }

        return response()->json(['status' => 'ok', 'accepted' => $accepted]);
    }

    /**
     * Store one reply and start the draft, or decide not to.
     *
     * Returns the number of conversations created, which is 0 or 1 — and is 0
     * on a redelivery, which is the whole point.
     *
     * @param  array<string, mixed>  $value
     */
    private function accept(string $userId, array $value): int
    {
        $externalId = $this->text($value, 'id');

        if ($externalId === null) {
            Log::info('A Threads event arrived without an id and was dropped', ['user' => $userId]);

            return 0;
        }

        $integration = ProjectIntegration::acrossProjects()
            ->where('provider', IntegrationProvider::Threads->value)
            ->where('config->user_id', $userId)
            ->first();

        if ($integration === null) {
            // Not an error. Meta delivers per app, and an app can be installed
            // on accounts this installation has never heard of.
            Log::info('A Threads event arrived for an account nobody has connected', [
                'user' => $userId,
                'event' => $externalId,
            ]);

            return 0;
        }

        if (ThreadsSelfAuthor::wrote($integration, $value)) {
            // Our own reply, pushed back to us. Stored it would be a `new`
            // conversation, and a `new` conversation starts a drafting job —
            // the engine answering itself, forever, at a per-reply cost. §4.1's
            // search path already refuses to treat our own posts as somebody
            // else's question; this is the same refusal on the event intake.
            Log::info('A Threads event was the connected account\'s own reply and was not stored', [
                'project' => $integration->project_id,
                'event' => $externalId,
            ]);

            return 0;
        }

        $channel = Channel::acrossProjects()
            ->where('project_id', $integration->project_id)
            ->where('type', ChannelType::Threads->value)
            ->orderBy('created_at')
            ->first();

        if ($channel === null) {
            // `interactions.channel_id` is not nullable and the composite key
            // ties the conversation to the channel's project, so there is
            // nowhere to put this. A project with a Threads credential and no
            // Threads channel is a half-finished setup, not a delivery fault.
            Log::warning('A Threads event arrived for a project with no Threads channel', [
                'project' => $integration->project_id,
                'event' => $externalId,
            ]);

            return 0;
        }

        $now = CarbonImmutable::now();
        $id = (string) Str::ulid();

        // The claim and the idempotency guarantee in one statement.
        // `insertOrIgnore` is `ON CONFLICT DO NOTHING` against
        // `unique (channel_id, external_id)`, and it reports how many rows it
        // wrote — so a redelivery, or two workers racing on the same event,
        // resolve to exactly one winner with no exception raised and no
        // transaction poisoned.
        //
        // Written through the query builder, which means the model's casts, its
        // ULID generation and its tenant stamping do not run. All three are
        // supplied here rather than worked around: `acrossProjects()` because a
        // webhook has no current tenant and the project comes from the payload.
        $written = Interaction::acrossProjects()->insertOrIgnore([
            'id' => $id,
            'project_id' => $integration->project_id,
            'channel_id' => $channel->getKey(),
            'external_id' => $externalId,
            'parent_external_id' => $this->nestedId($value, 'replied_to'),
            'root_external_id' => $this->nestedId($value, 'root_post'),
            'author' => $this->text($value, 'username') ?? 'unknown',
            'author_handle' => $this->handle($value),
            'text' => $this->text($value, 'text') ?? '',
            'permalink' => $this->text($value, 'permalink'),
            'received_at' => $this->receivedAt($value) ?? $now,
            'state' => InteractionState::New->value,
            'raw' => json_encode($value),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($written === 0) {
            // A redelivery, and nothing is lost by stopping here. This is not
            // an "someone else appears to be working on it" branch — those are
            // how work quietly disappears. `ON CONFLICT DO NOTHING` fired,
            // which means a committed row with this `(channel_id, external_id)`
            // already exists: the conversation is in the duty queue, visible to
            // the operator, whether or not its draft ever arrives. Answered 200
            // by the caller, on purpose — Meta stops retrying an event it
            // believes arrived, which it did.
            return 0;
        }

        // The id is the one generated above rather than one read back. The
        // insert reported a row, so that row is this one; a re-select would
        // only add a query and a nullable to reason about.
        //
        // Straight to the queue rather than to `engine:tick` — see the job's
        // docblock, and `EngineTickCommand::isBusy()`, which would hold this
        // behind any running pipeline.
        DraftInteractionReplyJob::dispatch($id);

        return 1;
    }

    /**
     * Flatten the delivery into events, each with the account it is about.
     *
     * Two shapes are accepted. `entry[].changes[].value` is the Graph webhook
     * convention and carries the account on the entry; `values[]` is the flatter
     * form in Threads' own examples, where the account is at the top level.
     *
     * @return list<array{user_id: string, value: array<string, mixed>}>
     */
    private function events(Request $request): array
    {
        $payload = $request->json()->all();

        $events = [];

        $entries = $payload['entry'] ?? null;

        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $userId = $this->text($entry, 'id');
                $changes = $entry['changes'] ?? null;

                if ($userId === null || ! is_array($changes)) {
                    continue;
                }

                foreach ($changes as $change) {
                    $value = is_array($change) ? ($change['value'] ?? null) : null;

                    if (is_array($value)) {
                        /** @var array<string, mixed> $value */
                        $events[] = ['user_id' => $userId, 'value' => $value];
                    }
                }
            }
        }

        $values = $payload['values'] ?? null;
        $topLevelUser = $this->text($payload, 'id') ?? $this->text($payload, 'user_id');

        if (is_array($values) && $topLevelUser !== null) {
            foreach ($values as $value) {
                if (is_array($value)) {
                    /** @var array<string, mixed> $value */
                    $events[] = ['user_id' => $topLevelUser, 'value' => $value];
                }
            }
        }

        return $events;
    }

    /**
     * A `hub.*` parameter.
     *
     * Read under the underscored name first, and this is not defensiveness for
     * its own sake: PHP's own query parsing rewrites `.` to `_` in parameter
     * names, so `hub.mode=subscribe` on the wire is `hub_mode` by the time any
     * request object sees it. Reading `hub.mode` alone is the way this endpoint
     * fails the handshake for reasons nobody can see in the logs. The dotted
     * name is tried as well in case something in front of the app preserves it.
     */
    private function hub(Request $request, string $name): string
    {
        foreach (["hub_{$name}", "hub.{$name}"] as $key) {
            $value = $request->query->get($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function text(array $value, string $key): ?string
    {
        $found = $value[$key] ?? null;

        if (is_int($found)) {
            return (string) $found;
        }

        return is_string($found) && $found !== '' ? $found : null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function handle(array $value): ?string
    {
        $username = $this->text($value, 'username');

        return $username === null ? null : '@'.ltrim($username, '@');
    }

    /**
     * `{"replied_to": {"id": "…"}}` — the thread, in the platform's own terms.
     *
     * @param  array<string, mixed>  $value
     */
    private function nestedId(array $value, string $key): ?string
    {
        $nested = $value[$key] ?? null;

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
     * When the reply was written, as the platform says.
     *
     * Null when it does not say, so the caller stamps arrival instead. §4.2 is
     * measured on `answered_at - received_at`, and a conversation stamped with
     * an unparseable date would either be ancient or in the future — one hides
     * at the top of the duty queue, the other at the bottom.
     *
     * @param  array<string, mixed>  $value
     */
    private function receivedAt(array $value): ?CarbonImmutable
    {
        $timestamp = $value['timestamp'] ?? null;

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
