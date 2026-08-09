<?php

declare(strict_types=1);

namespace App\Research\DataForSeo;

use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Transport for the DataForSEO v3 API.
 *
 * Separate from the keyword source for one reason: this API does not report
 * failure the way HTTP does. **Every response is 200** except 401, 402 and 404 —
 * a rate limit, an internal timeout, a malformed field and an empty result all
 * arrive as a successful request carrying a `status_code` in the body. Laravel's
 * `$response->throw()` and `$response->serverError()` therefore see nothing, and
 * an adapter that trusted them would map every failure to "the API returned no
 * keywords" and quietly plan a month from an empty pool.
 *
 * So the envelope is unwrapped here, twice — once at the top level and once per
 * task — and turned into the exceptions the pipeline already knows how to sort
 * into "spend a retry" and "stop and tell somebody".
 */
class DataForSeoClient
{
    /**
     * Body codes worth retrying.
     *
     * Everything in the 50xxx family is theirs — internal timeouts, a third
     * party they depend on, an index update in progress. The four 4xxxx ones
     * are the exceptions to "4 means you did it wrong": a search engine failing
     * behind them, a task that died in flight, and the two rate limits.
     *
     * @var list<int>
     */
    private const array RETRYABLE = [40101, 40103, 40202, 40209];

    /** A well-formed question with no answer. Not a failure. */
    private const int NO_RESULTS = 40102;

    private const int OK = 20000;

    /**
     * Post one task and return its `result` array.
     *
     * DataForSEO takes a list of tasks per request and answers with a list of
     * results. We send one: the live endpoints accept only one task each, and
     * batching across projects would put two tenants' work in one call.
     *
     * `$timeout` overrides the configured one. Asking an assistant a question
     * is documented at up to 120 seconds because the model browses before it
     * answers, where a keyword lookup is two — one number cannot serve both
     * without either cutting the first off or holding a worker open pointlessly
     * on the second.
     *
     * @param  array<string, mixed>  $task
     * @return list<array<string, mixed>>
     */
    public function post(string $path, array $task, ?int $timeout = null): array
    {
        return $this->unwrap($this->send('post', $path, [$task], $timeout), $path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get(string $path): array
    {
        return $this->unwrap($this->send('get', $path), $path);
    }

    public function isConfigured(): bool
    {
        return $this->credential('login') !== '' && $this->credential('password') !== '';
    }

    /**
     * @param  list<array<string, mixed>>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $verb, string $path, ?array $body = null, ?int $timeout = null): array
    {
        $base = rtrim((string) config('research.dataforseo.base_url'), '/');

        $request = Http::withBasicAuth($this->credential('login'), $this->credential('password'))
            ->timeout($timeout ?? (int) config('research.dataforseo.timeout', 60))
            // The step owns the retry budget. A client that retried underneath
            // it would multiply the two and turn one slow SERP into a worker
            // held for several minutes.
            ->retry(0)
            ->acceptJson();

        try {
            $response = $verb === 'post'
                ? $request->post($base.$path, $body ?? [])
                : $request->get($base.$path);
        } catch (ConnectionException $e) {
            throw new RetryableStepFailure("DataForSEO was unreachable: {$e->getMessage()}", previous: $e);
        }

        // The only three statuses that are not 200. Named individually because
        // they need three different actions and the generic message for all of
        // them is "HTTP request returned status code 4xx".
        match ($response->status()) {
            401 => throw new TerminalStepFailure(
                'DataForSEO rejected the credentials (401). Note that DATAFORSEO_PASSWORD is the API '
                .'password from the API access dashboard, not the password you log into the site with. '
                .'If it was changed after the queue workers started, they are still holding the old one — '
                .'restart them (`docker compose restart horizon`) and run this again.'
            ),
            402 => throw new TerminalStepFailure(
                'DataForSEO says the account balance is empty (402). Top it up; nothing about this '
                .'request will succeed until then, so it is not worth a retry.'
            ),
            404 => throw new TerminalStepFailure("DataForSEO has no endpoint at {$path} (404)."),
            default => null,
        };

        if ($response->serverError()) {
            throw new RetryableStepFailure("DataForSEO answered {$response->status()}.");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function unwrap(array $body, string $path): array
    {
        if ($this->assertOk($body, $path, 'The request') === self::NO_RESULTS) {
            return [];
        }

        /** @var list<array<string, mixed>> $tasks */
        $tasks = is_array($body['tasks'] ?? null) ? array_values($body['tasks']) : [];

        if ($tasks === []) {
            // A 20000 envelope with nothing under it. Not documented as
            // possible, which is exactly why it is worth saying out loud rather
            // than returning [] and letting it read as "no keywords".
            throw new RetryableStepFailure("DataForSEO accepted {$path} but returned no task.");
        }

        if ($this->assertOk($tasks[0], $path, 'The task') === self::NO_RESULTS) {
            return [];
        }

        /** @var list<array<string, mixed>> $result */
        $result = is_array($tasks[0]['result'] ?? null) ? array_values($tasks[0]['result']) : [];

        return $result;
    }

    /**
     * Reads one `status_code` and either throws or returns it.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function assertOk(array $envelope, string $path, string $subject): int
    {
        $code = (int) ($envelope['status_code'] ?? 0);
        $message = (string) ($envelope['status_message'] ?? 'no message');

        if ($code === self::OK || $code === self::NO_RESULTS) {
            return $code;
        }

        if (in_array($code, self::RETRYABLE, true) || ($code >= 50000 && $code < 60000)) {
            throw new RetryableStepFailure("DataForSEO {$path}: {$message} ({$code}).");
        }

        // Everything else is a request that will be wrong again in five
        // minutes. The two that are not really about this request get said in
        // full, because both are fixed on their website rather than in the code
        // and neither is guessable from "status 40203".
        throw new TerminalStepFailure(match ($code) {
            40104 => "DataForSEO has not verified the account yet ({$code}). The credentials are right — "
                .'this got past authentication — but the API stays closed until verification is '
                .'completed at https://app.dataforseo.com/. Nothing in the code will change that.',
            40200, 40203 => "DataForSEO refused {$path} on cost grounds: {$message} ({$code}). "
                .'Either the balance is empty or the account cost limit is set below what this '
                .'request would spend.',
            40204 => "DataForSEO denied access to {$path}: {$message} ({$code}). "
                .'The endpoint needs to be enabled for the account before it will answer.',
            40207 => "DataForSEO refused the caller's IP for {$path}: {$message} ({$code}). "
                .'The account has IP whitelisting on and this server is not on the list.',
            default => "{$subject} to DataForSEO {$path} failed: {$message} ({$code}).",
        });
    }

    private function credential(string $key): string
    {
        $value = config("research.dataforseo.{$key}");

        return is_string($value) ? $value : '';
    }
}
