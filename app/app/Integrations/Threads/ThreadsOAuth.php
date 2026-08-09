<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use App\Http\Controllers\GoogleConnectionController;
use App\Integrations\Exceptions\ThreadsCredentialRejected;
use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Integrations\Google\GoogleOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Obtaining a Threads credential: consent screen out, a sixty-day token back.
 *
 * The sibling of {@see GoogleOAuth}, and deliberately the same shape — an
 * authorisation URL with a `state` to compare on the way back, one `exchange()`
 * that turns a code into everything the row needs, and the same three-way error
 * taxonomy so a caller can tell "reconnect" from "try later" from "this
 * installation is misconfigured".
 *
 * Until this class existed, {@see ThreadsConnection} could keep a token alive
 * and {@see ThreadsPublisher} could spend it, and there was no way to obtain
 * one: a `project_integrations` row with `provider=threads` could only be
 * written by hand.
 *
 * **Two exchanges, not one — the part that differs from Google.** Google's code
 * redeems straight into the pair it will use forever. Meta's redeems into a
 * *short-lived* token measured in an hour, which then has to be traded a second
 * time for the ~60-day long-lived one, at a different host, with a different
 * verb, and with the client secret in the query string rather than the body.
 * Stopping after the first exchange would store a credential that works for the
 * rest of the afternoon — the Threads equivalent of Google answering without a
 * refresh token, which {@see GoogleConnectionController}
 * refuses outright for the same reason. So both legs happen here, and a
 * connection is either complete or not made.
 *
 * There is no PKCE. Google's flow has it because Google offers it; Meta's
 * authorisation endpoint does not accept a `code_challenge` for Threads, so the
 * `state` and the client secret are the whole of what proves this callback
 * belongs to this browser and this application.
 *
 * **Scopes are comma-separated.** Meta's `scope` parameter is a comma list
 * where Google's is a space list. Sending spaces yields a consent screen for
 * one absurd scope named after all of them.
 *
 * **Why `/me` is part of the exchange.** §9 stores the Threads user id on the
 * integration's `config` because the API addresses everything as
 * `/{user-id}/threads`, and {@see ThreadsConnection::userId()} already expects
 * to find it there. Connection time is the only honest moment to learn it: the
 * short-lived response does carry a `user_id`, but nothing carries the username
 * the settings screen shows, and one extra call once per connection is cheaper
 * than a caller discovering at publish time that the field is missing.
 *
 * ⚠️ Written from Meta's published documentation and **not verified against a
 * live account**, exactly like {@see ThreadsClient} and {@see ThreadsSearch}.
 * The endpoints, the two-step exchange, the parameter names and — most of all —
 * the error bodies classified below are documented shapes asserted in the suite
 * and proven nowhere else.
 */
class ThreadsOAuth
{
    /** The consent screen. Note the host: not `graph.` and not versioned. */
    private const string AUTH_URL = 'https://threads.net/oauth/authorize';

    /** Leg one — the code for a short-lived token. A POST, form-encoded. */
    private const string TOKEN_URL = 'https://graph.threads.net/oauth/access_token';

    /** Leg two — the short-lived token for a long-lived one. A GET. */
    private const string EXCHANGE_URL = 'https://graph.threads.net/access_token';

    /** Who the operator just connected as. */
    private const string ME_URL = 'https://graph.threads.net/v1.0/me';

    /** Meta's documented life for a long-lived token, if it declines to say. */
    private const int DEFAULT_LIFETIME = 5_184_000;

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * Where to send the operator, and the secret to keep until they return.
     *
     * No verifier beside the state, unlike Google's: see the class docblock.
     *
     * @return array{url: string, state: string}
     */
    public function authorisationUrl(): array
    {
        $this->assertConfigured();

        $state = Str::random(40);

        return [
            'url' => self::AUTH_URL.'?'.http_build_query([
                'client_id' => $this->clientId(),
                'redirect_uri' => $this->redirect(),
                'scope' => implode(',', $this->scopes()),
                'response_type' => 'code',
                'state' => $state,
            ]),
            'state' => $state,
        ];
    }

    /**
     * Redeem the code, all the way to a credential worth storing.
     *
     * Three calls, and the caller sees one answer or one exception. A failure
     * anywhere in the chain leaves nothing behind, which is the property the
     * controller depends on: a half-finished connection is worse than none,
     * because the settings screen would report it as connected.
     *
     * @return array{
     *     access_token: string,
     *     expires_at: Carbon,
     *     scopes: list<string>,
     *     user_id: string,
     *     username: string|null,
     * }
     */
    public function exchange(string $code): array
    {
        $this->assertConfigured();

        $short = $this->shortLived($code);
        $long = $this->longLived($short['access_token']);
        $user = $this->user($long['access_token']);

        // `/me` is authoritative; the short-lived response's `user_id` is the
        // fallback, because it is a documented field Meta may or may not send.
        $userId = $user['id'] ?? $short['user_id'];

        if ($userId === null) {
            // Every publishing path addresses `/{user-id}/threads`. Storing a
            // connection without one produces a row that looks connected and
            // cannot post.
            throw new ThreadsUnavailable('Threads did not say which account this token belongs to.');
        }

        return [
            'access_token' => $long['access_token'],
            'expires_at' => $long['expires_at'],
            'scopes' => $short['scopes'],
            'user_id' => $userId,
            'username' => $user['username'],
        ];
    }

    public function redirect(): string
    {
        return (string) config('services.threads.redirect', '');
    }

    /**
     * The scopes this installation asks for (§2), `threads_keyword_search`
     * among them — which §11.2 says is approved separately, so asking for it is
     * not the same as getting it.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        $scopes = config('services.threads.scopes', []);

        return is_array($scopes)
            ? array_values(array_filter(array_map(strval(...), $scopes)))
            : [];
    }

    /**
     * Leg one. Also the only place the granted permissions are ever named.
     *
     * @return array{access_token: string, user_id: string|null, scopes: list<string>}
     */
    private function shortLived(string $code): array
    {
        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirect(),
                'code' => $code,
            ]);
        } catch (ConnectionException $e) {
            throw new ThreadsUnavailable("Threads' token endpoint did not answer: {$e->getMessage()}");
        }

        $this->assertAccepted($response);

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new ThreadsUnavailable('Threads answered the code exchange without an access token.');
        }

        $userId = $response->json('user_id');

        return [
            'access_token' => $token,
            'user_id' => is_scalar($userId) && (string) $userId !== '' ? (string) $userId : null,
            'scopes' => $this->granted($response),
        ];
    }

    /**
     * Leg two. A GET with the secret in the query string, which is Meta's
     * design and not a slip.
     *
     * @return array{access_token: string, expires_at: Carbon}
     */
    private function longLived(string $shortLived): array
    {
        try {
            $response = Http::timeout(15)->get(self::EXCHANGE_URL, [
                'grant_type' => 'th_exchange_token',
                'client_secret' => $this->clientSecret(),
                'access_token' => $shortLived,
            ]);
        } catch (ConnectionException $e) {
            throw new ThreadsUnavailable("Threads' exchange endpoint did not answer: {$e->getMessage()}");
        }

        $this->assertAccepted($response);

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            // The short-lived token in hand is not worth storing — it dies
            // within the hour and takes the connection with it — so this is a
            // failed connection rather than a degraded one.
            throw new ThreadsUnavailable('Threads answered the long-lived exchange without a token.');
        }

        $lifetime = $response->json('expires_in');
        $lifetime = is_numeric($lifetime) ? (int) $lifetime : self::DEFAULT_LIFETIME;

        return [
            'access_token' => $token,
            // A day short of what Meta said, never less than an hour — the
            // same trim {@see ThreadsConnection} applies on renewal, for the
            // same reason: a token that expires while a request is in flight
            // is a request lost for nothing.
            'expires_at' => Carbon::now()->addSeconds(max(3_600, $lifetime - 86_400)),
        ];
    }

    /**
     * Who the token belongs to.
     *
     * @return array{id: string|null, username: string|null}
     */
    private function user(string $token): array
    {
        try {
            $response = Http::withToken($token)->timeout(15)->acceptJson()
                ->get(self::ME_URL, ['fields' => 'id,username']);
        } catch (ConnectionException $e) {
            throw new ThreadsUnavailable("Threads did not answer who this token belongs to: {$e->getMessage()}");
        }

        $this->assertAccepted($response);

        $id = $response->json('id');
        $username = $response->json('username');

        return [
            'id' => is_scalar($id) && (string) $id !== '' ? (string) $id : null,
            'username' => is_string($username) && $username !== '' ? $username : null,
        ];
    }

    /**
     * What the operator actually granted, when Meta says.
     *
     * Not every documented response carries this, and the ones that do spell it
     * `permissions` rather than Google's `scope` — sometimes an array,
     * sometimes a comma list. Absent, the caller falls back to what was asked
     * for, which is the honest default: {@see ThreadsSearch} treats a scope
     * list that excludes `threads_keyword_search` as a degradation to be
     * believed for a week, and inferring one from silence would degrade
     * listening on every installation whose Meta app is perfectly fine.
     *
     * @return list<string>
     */
    private function granted(Response $response): array
    {
        $permissions = $response->json('permissions');

        if (is_string($permissions) && $permissions !== '') {
            $permissions = explode(',', $permissions);
        }

        if (! is_array($permissions) || $permissions === []) {
            return $this->scopes();
        }

        return array_values(array_filter(array_map(
            static fn (mixed $scope): string => trim((string) (is_scalar($scope) ? $scope : '')),
            $permissions,
        )));
    }

    /**
     * Turn a refusal into the right one of three exceptions.
     *
     * The same split {@see GoogleOAuth::token()} makes, for the same reason: a
     * caller that cannot tell a dead grant from a busy platform either retries
     * something that will never work or gives up on something that would have
     * worked in a minute.
     *
     * Meta answers in two shapes depending on which endpoint refused —
     * `{"error_type":…,"error_message":…}` from the OAuth endpoints and
     * `{"error":{"message":…,"code":…}}` from the Graph — so both are read.
     */
    private function assertAccepted(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        [$message, $code] = $this->error($response);

        // The window being full or the platform being unwell. Time fixes both.
        if ($status === 429 || $status >= 500) {
            throw new ThreadsUnavailable($message);
        }

        // Our app id or secret, not the operator's account. Terminal and named
        // after the two variables somebody has to go and set, because a
        // credential that will never work must not read as "try again".
        if ($this->isOurConfiguration($message, $code)) {
            throw new RuntimeException(
                "Threads rejected this installation's app credentials ({$message}). Check THREADS_APP_ID and THREADS_APP_SECRET."
            );
        }

        throw new ThreadsCredentialRejected($message);
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function error(Response $response): array
    {
        $graph = $response->json('error');

        if (is_array($graph)) {
            $message = $graph['message'] ?? null;
            $code = $graph['code'] ?? null;

            return [
                is_string($message) && $message !== '' ? $message : "Threads answered {$response->status()}.",
                is_numeric($code) ? (int) $code : null,
            ];
        }

        $message = $response->json('error_message');
        $code = $response->json('code');

        return [
            is_string($message) && $message !== '' ? $message : "Threads answered {$response->status()}.",
            is_numeric($code) ? (int) $code : null,
        ];
    }

    /**
     * Whether the refusal is about this installation rather than the operator.
     *
     * Matched on the message as well as the code because Meta's OAuth errors
     * carry a code of 101 for "application does not exist" and a plain 400 with
     * a sentence for a mismatched redirect URI — and a mismatched redirect URI
     * is the single likeliest thing to be wrong the first time somebody sets
     * this up. Erring towards "your configuration" is the cheap direction: the
     * worst it costs is an operator reading an accurate sentence about
     * variables they can check.
     */
    private function isOurConfiguration(string $message, ?int $code): bool
    {
        if (in_array($code, [101, 191], true)) {
            return true;
        }

        $message = mb_strtolower($message);

        foreach (['client_id', 'client_secret', 'redirect_uri', 'redirect uri', 'invalid platform app'] as $hint) {
            if (str_contains($message, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Threads is not set up on this installation: THREADS_APP_ID and THREADS_APP_SECRET are missing.'
            );
        }
    }

    private function clientId(): string
    {
        return (string) config('services.threads.client_id', '');
    }

    private function clientSecret(): string
    {
        return (string) config('services.threads.client_secret', '');
    }
}
