<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Integrations\Exceptions\ThreadsCredentialRejected;
use App\Integrations\Exceptions\ThreadsRefused;
use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Integrations\Threads\ThreadsClient;
use App\Models\Channel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The publishing budget of §2, counted in actions and read from the API (§9).
 *
 * Two sentences of the spec shape everything here. §2: "250 действий /
 * пользователь / 24 ч (скользящее окно)". §9: "лимит читается у API, а не
 * угадывается". So the ceiling is asked of
 * `GET /{user-id}/threads_publishing_limit` and only falls back to
 * `config('social.threads.publish_actions_per_day')` when the platform does not
 * answer with one — a hardcoded 250 that the platform has quietly changed is a
 * budget that either wastes the account's capacity or overruns it, and both
 * failures are silent. Read is not the same as adopted, though: the configured
 * number stays the ceiling and the API can only lower it. See {@see read()}.
 *
 * **An action is not a post.** Publishing is two-phase, so one segment costs a
 * container creation *and* a publish, and the three-segment chain §2 allows
 * costs six. Counting posts would let a chain-heavy day spend three times the
 * budget the operator was shown. Nothing here counts posts; {@see spend()} is
 * called once per accepted API call by the transport that made it, which is the
 * only place that knows what was actually spent rather than what was planned.
 *
 * **Sliding, not daily.** The window is a list of timestamps rather than a
 * counter with a midnight reset, because that is what the platform enforces:
 * a counter would let an account spend the whole budget at 23:50 and the whole
 * budget again at 00:10. Holding the timestamps also makes
 * {@see availableAt()} answerable — the moment the window frees enough room for
 * a specific publication, which is a far better retry time than the next rung
 * of a generic backoff ladder.
 *
 * **Per channel.** §9 says "токен-бакет на канал". The platform counts per
 * user, and a user is one connected account; the channel is the row that stands
 * for that account here, and keying on it means two projects on one
 * installation never share a budget they do not share on the platform.
 *
 * The count is cache-backed and therefore approximate: two workers spending at
 * the same instant can lose one increment of the read-modify-write. That is a
 * deliberate trade. A lost increment under-counts by one action out of 250, the
 * ceiling read from the API absorbs it, and the platform's own 429 becomes
 * {@see ThreadsUnavailable} — retryable — if it does not. Serialising every
 * action behind a lock to protect the 250th of a budget would cost a lock on
 * every publish.
 *
 * ⚠️ Written from Meta's published documentation and **not verified against a
 * live account**, exactly like {@see ThreadsClient} and the Ahrefs and Google
 * adapters. The limit endpoint's field names — `quota_usage`, `quota_total`,
 * `quota_duration` — are the documented ones, asserted in the suite and proven
 * nowhere else.
 */
class ThreadsRateLimiter
{
    /** The window §2 states, used until the API states its own. */
    private const int FALLBACK_WINDOW = 86_400;

    /** How long a ceiling read stays good. Cheap to refresh, dull to re-read. */
    private const int CEILING_TTL = 1_800;

    /**
     * The soonest a deferred publication is worth retrying when the window
     * holds nothing we recorded ourselves — see {@see availableAt()}.
     */
    private const int BLIND_PROBE = 900;

    public function __construct(private readonly ThreadsClient $client) {}

    /**
     * Whether `$actions` more calls fit inside the account's window.
     *
     * The user id and token are optional so that the answer degrades rather
     * than fails: without them the ceiling is the configured one, which is the
     * §2 number and never larger than what the platform allows.
     */
    public function allows(Channel $channel, int $actions, ?string $userId = null, ?string $token = null): bool
    {
        return $this->remaining($channel, $userId, $token) >= $actions;
    }

    /** How many actions the account has left in the current window. */
    public function remaining(Channel $channel, ?string $userId = null, ?string $token = null): int
    {
        $quota = $this->quota($channel, $userId, $token);

        return max(0, $quota['limit'] - $this->spent($channel, $quota));
    }

    /** The ceiling §9 asks to read rather than guess. */
    public function ceiling(Channel $channel, ?string $userId = null, ?string $token = null): int
    {
        return $this->quota($channel, $userId, $token)['limit'];
    }

    /**
     * Record actions the platform has accepted.
     *
     * Called after the call rather than before it. A container creation that
     * was refused cost the account nothing, and pre-counting would shrink the
     * budget every time the platform said no.
     *
     * The credential is taken here as well as in {@see allows()} so that both
     * halves of a decision use the same window. A cache that expired between
     * the two — half an hour is a long publication, but a flush is instant —
     * would otherwise have `allows()` judge against the platform's window and
     * `spend()` record against the configured one, and a window recorded
     * against the wrong length ages its timestamps out at the wrong moment.
     */
    public function spend(Channel $channel, int $actions = 1, ?string $userId = null, ?string $token = null): void
    {
        if ($actions < 1) {
            return;
        }

        $window = $this->quota($channel, $userId, $token)['window'];
        $now = Carbon::now()->getTimestamp();

        $spent = [...$this->window($channel, $window), ...array_fill(0, $actions, $now)];

        Cache::put($this->windowKey($channel), $spent, $window);
    }

    /**
     * When the window will hold `$actions` again.
     *
     * The point of keeping timestamps rather than a counter: this is the moment
     * enough of the oldest actions age out, which is the retry time a deferred
     * publication actually wants. A generic ladder would wake the job at one
     * minute and burn an attempt to learn nothing.
     *
     * A window we did not fill ourselves — the platform reporting usage from
     * another client, or a cache that was flushed — cannot be aged, so it gets
     * a short probe instead of a pessimistic day.
     */
    public function availableAt(Channel $channel, int $actions = 1, ?string $userId = null, ?string $token = null): Carbon
    {
        $quota = $this->quota($channel, $userId, $token);
        $recorded = $this->window($channel, $quota['window']);
        $missing = $this->spent($channel, $quota) + $actions - $quota['limit'];

        if ($missing <= 0) {
            return Carbon::now();
        }

        if ($missing > count($recorded)) {
            return Carbon::now()->addSeconds(self::BLIND_PROBE);
        }

        sort($recorded);

        return Carbon::createFromTimestamp($recorded[$missing - 1])->addSeconds($quota['window']);
    }

    /** Forget this channel's window — the account was reconnected, or a test. */
    public function forget(Channel $channel): void
    {
        Cache::forget($this->windowKey($channel));
        Cache::forget($this->quotaKey($channel));
    }

    /**
     * What the account has spent, ours and the platform's, whichever is larger.
     *
     * The platform's own `quota_usage` counts every client publishing as that
     * user, including a person posting from the app; ours counts what this
     * installation did and is current between limit reads. Taking the larger of
     * the two is the only combination that never overspends.
     *
     * @param  array{limit: int, window: int, usage: int|null}  $quota
     */
    private function spent(Channel $channel, array $quota): int
    {
        return max(count($this->window($channel, $quota['window'])), $quota['usage'] ?? 0);
    }

    /**
     * The timestamps still inside the window.
     *
     * Filtered on read rather than trimmed on write: a window that is never
     * written to still has to age out, and a publication deferred for a day
     * writes nothing at all.
     *
     * @return list<int>
     */
    private function window(Channel $channel, int $window): array
    {
        $stored = Cache::get($this->windowKey($channel));
        $since = Carbon::now()->getTimestamp() - $window;

        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $at): int => (int) $at, $stored),
            static fn (int $at): bool => $at > $since,
        ));
    }

    /**
     * The account's ceiling, its window and the platform's own usage.
     *
     * Cached for half an hour: it is a fact about the account rather than about
     * this publication, and asking on every segment would spend a request per
     * call to learn a number that changes when Meta changes it.
     *
     * Every Threads failure is swallowed. A ceiling read is not the publication
     * — the call that follows it hits the same wall a moment later and gets to
     * classify it properly, where failing here would turn a transient blip into
     * a dead-lettered post.
     *
     * @return array{limit: int, window: int, usage: int|null}
     */
    private function quota(Channel $channel, ?string $userId = null, ?string $token = null): array
    {
        /** @var array{limit: int, window: int, usage: int|null}|null $cached */
        $cached = Cache::get($this->quotaKey($channel));

        if (is_array($cached)) {
            return $cached;
        }

        $fallback = [
            'limit' => (int) config('social.threads.publish_actions_per_day', 250),
            'window' => self::FALLBACK_WINDOW,
            'usage' => null,
        ];

        if ($userId === null || $token === null) {
            return $fallback;
        }

        try {
            $answer = $this->client->get("{$userId}/threads_publishing_limit", [
                'fields' => 'quota_usage,config',
            ], $token);
        } catch (ThreadsUnavailable|ThreadsRefused|ThreadsCredentialRejected) {
            return $fallback;
        }

        $stated = $this->read($answer, $fallback);

        Cache::put($this->quotaKey($channel), $stated, self::CEILING_TTL);

        return $stated;
    }

    /**
     * `{"data":[{"quota_usage":4,"config":{"quota_total":250,"quota_duration":86400}}]}`
     *
     * Each field falls back on its own. An answer carrying a usage but no
     * config is still worth having, and a `quota_total` of zero is a shape we
     * do not understand rather than an account that may publish nothing.
     *
     * **Read, but not adopted whole.** §9 says the limit is read from the API
     * rather than guessed, and it is; it does not say the API is trusted to
     * raise a ceiling the operator set. §2's 250 is a ceiling and never a plan,
     * which is also why nothing in `config/social.php` reads `env()` — so a
     * `quota_total` of 100000, whether Meta means it or the field moved, is
     * clamped to the configured number rather than spent. The two directions
     * are deliberately opposite: for the ceiling the safe answer is the smaller
     * of the two, and for the window it is the longer one, because a short
     * window ages our own timestamps out early and lets the account overrun.
     * Both disagreements are logged rather than swallowed — a platform that has
     * genuinely changed its limits is a config change somebody has to make, and
     * silently ignoring the number is how that never happens.
     *
     * @param  array<string, mixed>  $answer
     * @param  array{limit: int, window: int, usage: int|null}  $fallback
     * @return array{limit: int, window: int, usage: int|null}
     */
    private function read(array $answer, array $fallback): array
    {
        $data = $answer['data'] ?? [];
        $row = is_array($data) ? ($data[0] ?? []) : [];
        $row = is_array($row) ? $row : [];
        $config = is_array($row['config'] ?? null) ? $row['config'] : [];

        $limit = is_numeric($config['quota_total'] ?? null) ? (int) $config['quota_total'] : 0;
        $window = is_numeric($config['quota_duration'] ?? null) ? (int) $config['quota_duration'] : 0;
        $usage = is_numeric($row['quota_usage'] ?? null) ? (int) $row['quota_usage'] : null;

        $stated = [
            'limit' => $limit > 0 ? min($limit, $fallback['limit']) : $fallback['limit'],
            'window' => $window > 0 ? max($window, $fallback['window']) : $fallback['window'],
            'usage' => $usage,
        ];

        if (($limit > 0 && $limit !== $stated['limit']) || ($window > 0 && $window !== $stated['window'])) {
            Log::warning('Threads states publishing limits this installation will not adopt', [
                'stated_limit' => $limit,
                'stated_window' => $window,
                'using_limit' => $stated['limit'],
                'using_window' => $stated['window'],
            ]);
        }

        return $stated;
    }

    private function windowKey(Channel $channel): string
    {
        return 'threads:publish-actions:'.$channel->getKey();
    }

    private function quotaKey(Channel $channel): string
    {
        return 'threads:publish-quota:'.$channel->getKey();
    }
}
