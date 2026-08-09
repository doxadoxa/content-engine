<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use App\Integrations\Exceptions\ThreadsCredentialRejected;
use App\Integrations\Exceptions\ThreadsRefused;
use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The Threads Graph API, `graph.threads.net/v1.0` (§2).
 *
 * Thin on purpose: this speaks HTTP and classifies failures, and knows nothing
 * about publishing, listening or rate budgets. Its whole job is to make the
 * three answers the rest of the adapter has to tell apart genuinely distinct —
 * a transport hiccup worth retrying, a refusal that will be refused again in
 * twelve hours, and a credential the platform has stopped honouring.
 *
 * That third one is not pedantry. §11.2 says the API returns an **empty array**
 * for a "sensitive" keyword, and §4.1 says the adapter must not read that as a
 * failure. An error taxonomy that collapses "no results" into "something went
 * wrong" would make the listening contour retry its way through the daily
 * search budget on a word that will never return anything.
 *
 * Every call goes through {@see PublicHttpTarget}, like every other outbound
 * request in this codebase: validated, DNS-pinned, no redirects, and no
 * client-side retries — retries are the caller's ladder to walk, not Guzzle's.
 *
 * ⚠️ Written from Meta's published documentation and **not verified against a
 * live account**, exactly like the Ahrefs and Google adapters. Nothing in the
 * suite reaches the network; the shapes below are the documented ones,
 * asserted. Treat every response mapping here as unproven until someone runs it
 * against a real token.
 */
class ThreadsClient
{
    /** Long enough for a container to be accepted, short enough to fail a job. */
    public const int TIMEOUT = 30;

    private const string BASE_URL = 'https://graph.threads.net/v1.0';

    public function __construct(private readonly PublicHttpTarget $targets) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], ?string $token = null): array
    {
        return $this->send('get', $path, $query, $token);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = [], ?string $token = null): array
    {
        return $this->send('post', $path, $payload, $token);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $parameters, ?string $token): array
    {
        $url = self::BASE_URL.'/'.ltrim($path, '/');

        try {
            $target = $this->targets->validate($url);
        } catch (UnsafePublicUrl $e) {
            // The base URL is a constant, so this only fires when the outbound
            // policy itself refuses it — a deployment whose allowed ports or
            // HTTPS requirement rule out the platform. That is a configuration
            // fault, and retrying it forever would hide it.
            throw new ThreadsRefused("Threads is unreachable under this installation's outbound policy: {$e->getMessage()}");
        }

        $request = Http::withToken((string) $token)
            ->timeout(self::TIMEOUT)
            ->retry(0)
            ->withoutRedirecting()
            ->withOptions($target->httpOptions())
            ->acceptJson();

        try {
            $response = $method === 'post'
                ? $request->asForm()->post($target->url, $parameters)
                : $request->get($target->url, $parameters);
        } catch (ConnectionException $e) {
            // Flagged as unanswered, not merely unavailable. A caller in the
            // middle of a two-phase publish has to tell "the platform declined"
            // from "the platform said nothing" — see the factory's docblock.
            throw ThreadsUnavailable::noAnswer("Threads did not answer: {$e->getMessage()}");
        }

        $status = $response->status();
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful()) {
            return $body;
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = is_string($error['message'] ?? null) ? $error['message'] : "Threads answered {$status}.";
        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;

        if ($this->isExpiredCredential($status, $code)) {
            throw new ThreadsCredentialRejected($message);
        }

        // 429 and 5xx are the window being full or the platform being unwell.
        // Both pass with time and neither is fixed by changing the request.
        if ($status === 429 || $status >= 500) {
            throw new ThreadsUnavailable($message);
        }

        throw new ThreadsRefused($message, $status);
    }

    /**
     * Whether the platform is saying the token is finished rather than the
     * request being wrong.
     *
     * Meta answers 401 in some flows and a 400 carrying an OAuth error subcode
     * in others, so the status line alone is not enough. Getting this wrong in
     * the lenient direction is the expensive one: a dead token read as a
     * malformed request dead-letters every delivery with a message that sends
     * the operator looking at the post instead of at the connection.
     */
    private function isExpiredCredential(int $status, ?int $code): bool
    {
        // 190 is Meta's "access token has expired, been revoked, or is
        // otherwise invalid"; 102 is the session-level equivalent.
        return $status === 401 || in_array($code, [102, 190], true);
    }
}
