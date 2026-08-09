<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Models\Project;
use App\Publishing\ThreadsRateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The listening budget of §2: 2 200 requests per user per 24 h, sliding.
 *
 * **Why this is a sibling of {@see ThreadsRateLimiter} and not the same class.**
 * The two share a shape — a list of timestamps in the cache, filtered on read —
 * and nothing else. Three things differ, and each of them is a rule rather than
 * a number:
 *
 * 1. *What counts.* The publishing limiter spends once per accepted API call.
 *    Here §2 says "пустые ответы не считаются", so an answer that came back
 *    empty is a request that never happened as far as the window is concerned.
 *    Folding that into a shared `spend()` would mean a flag whose two branches
 *    have no code in common.
 * 2. *Where the ceiling comes from.* §9 requires the publishing ceiling to be
 *    read from `GET /{user-id}/threads_publishing_limit` rather than guessed,
 *    and the API answers with `quota_total`, `quota_duration` and a usage the
 *    limiter has to reconcile against its own count. There is no documented
 *    endpoint that states the search ceiling, so this one is the config number
 *    and only the config number — a shared class would carry the whole quota
 *    negotiation for a caller that can never use it.
 * 3. *What it is keyed on.* See below.
 *
 * A generalisation of the two would be a class with two accounting modes, two
 * ceiling sources and one shared array filter. That is bigger than both of
 * these, and the part it shares is nine lines.
 *
 * **Keyed on the connected Threads user, because that is what the platform
 * counts.** §2 states the limit per *user*: "2 200 запросов в сутки на
 * пользователя". Not per channel — §9 keys the publishing bucket on the channel
 * because the channel is the publishing target, and listening has no target; it
 * runs off `ProjectIntegration` and runs whether or not the project has a
 * Threads channel enabled, since §4.1 is the contour that "окупается сразу,
 * даже если не опубликовать ни одного поста".
 *
 * And not per project either, which is what this used to be. Two projects may
 * be connected to one Threads account — an agency running a brand's site and
 * its shop, one login between them — and per project each would get a full
 * 2 200 while the platform enforces 2 200 across both. The engine would then
 * believe it had budget left, keep asking, and collect 429s it reads as an
 * outage. The account id is the thing the platform meters, so it is the thing
 * this counts against.
 *
 * A project with no connection has nothing to meter and falls back to its own
 * id. Nothing can search without a connection, so that key can only ever be
 * touched by a caller asking how much budget it *would* have — a panel, a test
 * — and answering "the full ceiling, under your own name" is truer than
 * pretending some other account's spend applies to you.
 *
 * **Sliding, not daily.** As with the publishing limiter: a counter reset at
 * midnight lets an account spend the ceiling twice in twenty minutes, which the
 * platform's own window would refuse.
 *
 * Cache-backed and therefore approximate under concurrent workers, on the same
 * trade the publishing limiter documents — except that here the consequence is
 * milder still, because overrunning produces a 429, which
 * {@see ThreadsUnavailable} already classifies as
 * retryable.
 */
final class ThreadsSearchBudget
{
    /** §2 states the window in hours; nothing reads it back from the API. */
    private const int WINDOW = 86_400;

    public function __construct(private readonly ThreadsConnection $connection) {}

    /**
     * Whether `$requests` more searches fit inside the account's window.
     *
     * The one question a listening run asks before each call. §5 makes the
     * answer a stop rather than a failure: "Бюджет — потолок, а не план."
     */
    public function allows(Project $project, int $requests = 1): bool
    {
        return $this->remaining($project) >= $requests;
    }

    /** How many searches are left in the current window. */
    public function remaining(Project $project): int
    {
        return max(0, $this->ceiling() - count($this->window($project)));
    }

    /** The §2 ceiling, as configured. */
    public function ceiling(): int
    {
        return max(0, (int) config('social.threads.search_requests_per_day', 2_200));
    }

    /**
     * Record searches the platform actually answered with something.
     *
     * Called after the answer, never before it, and only for an answer that
     * carried results — §2: "пустые ответы не считаются". Counting a request
     * that returned nothing would let the "sensitive" words of §11.2 eat the
     * day's listening: those words return empty every time, forever, and a
     * project with a dozen of them in its entity list would spend a third of
     * its budget an hour on answers the platform has already decided never to
     * give.
     *
     * **A refusal is charged, though.** The exemption §2 grants is to an empty
     * answer and not to every unhappy outcome: a refused request reached the
     * platform and was decided on, and nothing documents it as free. §5 settles
     * which way to guess — "Недобор допустим, перебор — нет" — so the engine
     * counts what it cannot verify rather than assuming it is free. See
     * {@see ThreadsSearch::search()}, which is where the call is made.
     */
    public function spend(Project $project, int $requests = 1): void
    {
        if ($requests < 1) {
            return;
        }

        $now = Carbon::now()->getTimestamp();

        Cache::put(
            $this->key($project),
            [...$this->window($project), ...array_fill(0, $requests, $now)],
            self::WINDOW,
        );
    }

    /**
     * When the window will hold `$requests` again.
     *
     * The reason the window is a list of timestamps rather than a counter: an
     * hourly listening run that is told "spent" wants to know whether to try
     * again next hour or tomorrow morning, and this is the only way to answer
     * without waiting for the platform to refuse.
     */
    public function availableAt(Project $project, int $requests = 1): Carbon
    {
        $spent = $this->window($project);
        $missing = count($spent) + $requests - $this->ceiling();

        if ($missing <= 0) {
            return Carbon::now();
        }

        if ($missing > count($spent)) {
            // Asking for more than a whole empty window holds. Nothing ages
            // out into that, so the honest answer is the end of the window.
            return Carbon::now()->addSeconds(self::WINDOW);
        }

        sort($spent);

        return Carbon::createFromTimestamp($spent[$missing - 1])->addSeconds(self::WINDOW);
    }

    /** Forget this project's window — the account was reconnected, or a test. */
    public function forget(Project $project): void
    {
        Cache::forget($this->key($project));
    }

    /**
     * The timestamps still inside the window.
     *
     * Filtered on read rather than trimmed on write, because a project that
     * stopped searching an hour ago writes nothing and still has to age out.
     *
     * @return list<int>
     */
    private function window(Project $project): array
    {
        $stored = Cache::get($this->key($project));

        if (! is_array($stored)) {
            return [];
        }

        $since = Carbon::now()->getTimestamp() - self::WINDOW;

        return array_values(array_filter(
            array_map(static fn (mixed $at): int => (int) $at, $stored),
            static fn (int $at): bool => $at > $since,
        ));
    }

    /**
     * The account this project's searches are charged to.
     *
     * Resolved per call rather than cached on the instance: a project can be
     * disconnected and reconnected to a different account inside one process,
     * and a stale key would charge the new account for the old one's window —
     * or, worse, leave the old one's spend unaccounted while the platform still
     * remembers it.
     */
    private function key(Project $project): string
    {
        $userId = $this->userId($project);

        return $userId === null
            ? 'threads:search-requests:project:'.$project->getKey()
            : 'threads:search-requests:user:'.$userId;
    }

    private function userId(Project $project): ?string
    {
        $integration = $this->connection->for($project);

        if ($integration === null) {
            return null;
        }

        $userId = $integration->config['user_id'] ?? null;

        if (is_string($userId) && $userId !== '') {
            return $userId;
        }

        // A connection with no account id on it. Rare — the callback writes one
        // — and worth a line, because the fallback silently gives this project
        // a budget of its own again, which is the bug this keying fixed.
        Log::warning('A Threads connection has no user id, so its search budget is counted per project', [
            'project' => $project->getKey(),
            'integration' => $integration->getKey(),
        ]);

        return null;
    }
}
