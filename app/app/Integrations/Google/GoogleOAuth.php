<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use App\Integrations\Exceptions\ConnectionRevoked;
use App\Integrations\Exceptions\GoogleUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The three-legged part: consent screen out, tokens back.
 *
 * PKCE even though this is a confidential client with a secret. The extra
 * verifier costs one session key and closes the case where an authorisation
 * code leaks out of a redirect — through a proxy log, a referrer header, a
 * shared browser — and is redeemed by somebody who never saw the secret.
 *
 * `access_type=offline` with `prompt=consent` because the refresh token is the
 * whole point: Google issues one on first consent only, so an operator who
 * reconnects after we lost theirs would otherwise get an access token that dies
 * in an hour and a connection that silently stops working.
 */
class GoogleOAuth
{
    private const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const string REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * Where to send the operator, and the two secrets to keep until they return.
     *
     * @param  list<string>  $scopes
     * @return array{url: string, state: string, verifier: string}
     */
    public function authorisationUrl(array $scopes): array
    {
        $this->assertConfigured();

        $state = Str::random(40);
        $verifier = Str::random(96);

        return [
            'url' => self::AUTH_URL.'?'.http_build_query([
                'client_id' => $this->clientId(),
                'redirect_uri' => $this->redirect(),
                'response_type' => 'code',
                'scope' => implode(' ', $scopes),
                'access_type' => 'offline',
                // Without this, a second consent returns no refresh token and
                // the connection works for an hour and then stops.
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
                'state' => $state,
                'code_challenge' => $this->challenge($verifier),
                'code_challenge_method' => 'S256',
            ]),
            'state' => $state,
            'verifier' => $verifier,
        ];
    }

    /**
     * Redeem the code. Returns the grant, including the scopes actually given —
     * which is not necessarily the scopes asked for, because the consent screen
     * lets the operator untick one.
     *
     * @return array{
     *     refresh_token: string|null,
     *     access_token: string,
     *     expires_at: Carbon,
     *     scopes: list<string>,
     * }
     */
    public function exchange(string $code, string $verifier): array
    {
        return $this->token([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirect(),
            'code_verifier' => $verifier,
        ]);
    }

    /**
     * A fresh access token from a refresh token.
     *
     * @return array{
     *     refresh_token: string|null,
     *     access_token: string,
     *     expires_at: Carbon,
     *     scopes: list<string>,
     * }
     */
    public function refresh(string $refreshToken): array
    {
        return $this->token([
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
    }

    /**
     * Tell Google to forget the grant.
     *
     * Best effort by design: the operator asked to disconnect, and a revoke
     * endpoint that is down must not leave a token in our database because the
     * request failed. Deleting our copy is the part we control.
     */
    public function revoke(string $token): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            return Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token])->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function redirect(): string
    {
        return (string) config('services.google.redirect', '');
    }

    /**
     * @param  array<string, string>  $payload
     * @return array{
     *     refresh_token: string|null,
     *     access_token: string,
     *     expires_at: Carbon,
     *     scopes: list<string>,
     * }
     */
    private function token(array $payload): array
    {
        $this->assertConfigured();

        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                ...$payload,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ]);
        } catch (ConnectionException $e) {
            throw new GoogleUnavailable("Google's token endpoint did not answer: {$e->getMessage()}");
        }

        if ($response->failed()) {
            $error = (string) $response->json('error', '');

            // invalid_grant is Google saying the grant is gone — revoked,
            // expired, or the account's password changed. Retrying cannot fix
            // it; only the operator reconnecting can, so it has to surface as a
            // broken connection rather than as a step that keeps failing.
            if ($error === 'invalid_grant') {
                throw new ConnectionRevoked(
                    (string) $response->json('error_description', 'Google no longer accepts this connection.')
                );
            }

            // A wrong client id or secret is our configuration, not Google's
            // weather. Reported as terminal so a step fails once and says why
            // rather than retrying a credential that will never work.
            if (in_array($error, ['invalid_client', 'unauthorized_client', 'invalid_request'], true)) {
                throw new RuntimeException(
                    "Google rejected this installation's OAuth client ({$error}). Check GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET."
                );
            }

            throw new GoogleUnavailable("Google refused the token request: {$error}.");
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new GoogleUnavailable('Google answered without an access token.');
        }

        $refreshToken = $response->json('refresh_token');
        $scope = $response->json('scope');

        return [
            // Absent on a refresh, which is normal: the existing one stays
            // valid and the caller keeps what it already had.
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            'access_token' => $accessToken,
            // A minute short of what Google said, but never less than a
            // minute: a token that expires between our check and the request
            // that uses it is a 401 for no reason, and one that arrives
            // already expired is a refresh on every single call.
            'expires_at' => Carbon::now()->addSeconds(
                max(60, (int) $response->json('expires_in', 3600) - 60)
            ),
            'scopes' => is_string($scope) && $scope !== ''
                ? array_values(array_filter(explode(' ', $scope)))
                : [],
        ];
    }

    /**
     * The S256 challenge: base64url of the verifier's SHA-256, unpadded.
     */
    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Google is not set up on this installation: GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET are missing.'
            );
        }
    }

    private function clientId(): string
    {
        return (string) config('services.google.client_id', '');
    }

    private function clientSecret(): string
    {
        return (string) config('services.google.client_secret', '');
    }
}
