<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use App\Enums\IntegrationProvider;
use App\Integrations\Exceptions\ThreadsCredentialRejected;
use App\Integrations\Exceptions\ThreadsRefused;
use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Integrations\Google\GoogleConnection;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * A project's Threads credential, kept alive (§9).
 *
 * §9 puts the token here rather than on the channel and says why: "Токен живёт
 * в `ProjectIntegration` — там уже реализовано обновление для Google; `Channel`
 * держит только цель и тумблеры." So this is deliberately the same shape as
 * {@see GoogleConnection} — one method every caller goes through, which returns
 * a token that will still be valid when the next request goes out, or null.
 *
 * **Where the token lives, and why `refresh_token` is empty.** Google's flow
 * has two credentials: a refresh token that never expires and an access token
 * that lasts an hour. Threads has one. A long-lived token lasts about sixty
 * days and is renewed by presenting *itself* — there is no second credential to
 * exchange. So the long-lived token is the `access_token`, its sixty-day expiry
 * is `access_token_expires_at`, and `refresh_token` stays null for this
 * provider. Copying the token into both columns would have made
 * `ProjectIntegration::isUsable()` work without a change, and would also have
 * meant two encrypted copies of one secret drifting apart on every renewal.
 * {@see isUsable()} below asks the right question for this provider instead.
 *
 * **Renewal is proactive, not on failure.** A token is renewed while it still
 * has days left, because the platform refuses to renew an expired one — waiting
 * for a 401 means the credential is already dead and only a human reconnecting
 * fixes it. The platform also refuses to renew a token less than 24 hours old,
 * which is why {@see needsRenewal()} has a floor as well as a ceiling.
 *
 * ⚠️ Written from Meta's published documentation and not verified against a
 * live account. The renewal endpoint, its parameter name and the shape of its
 * answer are all unproven here.
 */
class ThreadsConnection
{
    /** Renew once the token has this long left of its ~60 days. */
    private const int RENEW_WITHIN_DAYS = 7;

    /** The platform refuses to renew a token younger than this. */
    private const int RENEW_AFTER_HOURS = 24;

    public function __construct(
        private readonly ThreadsClient $client,
        private readonly CurrentProject $current,
    ) {}

    /** The connection for a project, or for whichever project is current. */
    public function for(?Project $project = null): ?ProjectIntegration
    {
        $find = fn (): ?ProjectIntegration => ProjectIntegration::query()
            ->where('provider', IntegrationProvider::Threads->value)
            ->first();

        return $project === null ? $find() : $this->current->run($project, $find);
    }

    /**
     * Whether this installation could talk to Threads at all.
     *
     * Separate from having a connection: an installation with no app id has
     * nothing to connect *with*, and the settings screen should say that rather
     * than offering a button that fails.
     */
    public function isConfigured(): bool
    {
        return (string) config('services.threads.client_id') !== ''
            && (string) config('services.threads.client_secret') !== '';
    }

    /**
     * A token that will still be valid when the next request goes out.
     *
     * Null when there is nothing to work with — no connection, one the platform
     * has stopped honouring, or one whose renewal window was missed. Null
     * rather than an exception for the same reason {@see GoogleConnection} does
     * it: "not connected" is a state the caller has to handle anyway, and
     * making it an exception only moves the handling.
     */
    public function accessToken(ProjectIntegration $integration): ?string
    {
        if (! $this->isUsable($integration)) {
            return null;
        }

        $token = $integration->access_token;

        if ($token === null) {
            return null;
        }

        if ($this->needsRenewal($integration)) {
            $token = $this->renew($integration, $token) ?? $token;
        }

        // Asked again, because the two steps above can each end the connection
        // on their way past: an expiry that arrived before the renewal window
        // was ever used, and a refusal only a human reconnecting undoes. Both
        // call `markBroken()`, and both used to leave this method returning the
        // token it came in with — a credential the platform has just told us it
        // is finished with, handed to a caller who would spend it on a 401 it
        // could not explain.
        return $this->isUsable($integration) ? $token : null;
    }

    /**
     * The Threads user id every publishing path needs in its URL.
     *
     * Stored on the integration's config at connection time, because the API
     * addresses everything as `/{user-id}/threads` and looking it up per
     * request would spend a call from the budget of §2 to learn something that
     * never changes.
     */
    public function userId(ProjectIntegration $integration): ?string
    {
        $id = $integration->config['user_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Usable means: not marked broken, and there is a token to present.
     *
     * `ProjectIntegration::isUsable()` asks for a `refresh_token`, which is
     * Google's shape and not this one — see the class docblock.
     *
     * Marked impure because the answer is about mutable state rather than about
     * the argument: {@see accessToken()} asks twice with a renewal in between,
     * and the second answer is the interesting one precisely when it differs.
     *
     * @phpstan-impure
     */
    public function isUsable(ProjectIntegration $integration): bool
    {
        return $integration->failure_reason === null
            && $integration->getRawOriginal('access_token') !== null;
    }

    /**
     * Whether the token is inside its renewal window.
     *
     * Both bounds matter. Too late and the platform refuses an expired token;
     * too early and it refuses one under a day old. A token with no recorded
     * expiry is treated as due: an unknown age is not a reason to keep using a
     * credential indefinitely, and a renewal that is refused for being too
     * young costs one call and leaves the token working.
     */
    private function needsRenewal(ProjectIntegration $integration): bool
    {
        $expiresAt = $integration->access_token_expires_at;

        if ($expiresAt === null) {
            return true;
        }

        if ($expiresAt->isPast()) {
            // Nothing to renew from. Said out loud because the connection is
            // now dead and the operator's only route back is reconnecting.
            $integration->markBroken('The Threads token expired before it was renewed. Reconnect to grant access again.');

            return false;
        }

        $issuedAt = $integration->connected_at ?? $integration->updated_at;

        if ($issuedAt !== null && $issuedAt->diffInHours(Carbon::now()) < self::RENEW_AFTER_HOURS) {
            return false;
        }

        return $expiresAt->lessThanOrEqualTo(Carbon::now()->addDays(self::RENEW_WITHIN_DAYS));
    }

    /**
     * Present the token to get a fresh sixty days, or leave it alone.
     *
     * Never throws. A renewal that fails transiently is not a reason to fail
     * the publish that triggered it — the current token is still valid for days
     * by construction, so the caller keeps using it and the next attempt tries
     * again. Only an outright rejection marks the connection broken.
     */
    private function renew(ProjectIntegration $integration, string $token): ?string
    {
        try {
            $answer = $this->client->get('refresh_access_token', [
                'grant_type' => 'th_refresh_token',
                'access_token' => $token,
            ], $token);
        } catch (ThreadsCredentialRejected $e) {
            $integration->markBroken("Threads refused the connection: {$e->getMessage()} Reconnect to grant access again.");

            return null;
        } catch (ThreadsUnavailable|ThreadsRefused $e) {
            Log::warning('A Threads token could not be renewed', [
                'integration' => $integration->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $fresh = $answer['access_token'] ?? null;

        if (! is_string($fresh) || $fresh === '') {
            Log::warning('Threads answered a renewal without a token', [
                'integration' => $integration->getKey(),
            ]);

            return null;
        }

        $integration->forceFill([
            'access_token' => $fresh,
            // Trimmed by a day, as the Google adapter trims its hour: a token
            // that expires while a request is in flight is a request lost for
            // no reason.
            'access_token_expires_at' => Carbon::now()->addSeconds(
                max(3_600, (int) ($answer['expires_in'] ?? 5_184_000) - 86_400)
            ),
            'connected_at' => $integration->connected_at ?? Carbon::now(),
            'last_synced_at' => Carbon::now(),
            'failure_reason' => null,
        ])->save();

        return $fresh;
    }
}
