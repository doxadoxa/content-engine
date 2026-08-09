<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use App\Integrations\Exceptions\ThreadsRefused;
use App\Models\Project;
use App\Models\ProjectIntegration;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `GET /keyword_search` — the listening half of §4.1.
 *
 * §2 calls this "бесплатный first-party источник трендов и слушания", and §4.1
 * says the contour built on it pays for itself even if nothing is ever
 * published, because it hands the article planner live questions in live words.
 * So the job here is narrow and the failure modes are the interesting part.
 *
 * **The three answers of §11.2, and why none of them is a bare array.**
 *
 * 1. *An empty array for a "sensitive" word.* The spec could not be plainer:
 *    "это не ошибка транспорта, и адаптер не должен интерпретировать её как
 *    сбой". So it is {@see ThreadsSearchOutcome::Nothing} — no exception, no
 *    retry, and, per §2's "пустые ответы не считаются", nothing taken from the
 *    daily budget. A word the platform has decided to be quiet about is quiet
 *    forever; charging the budget for it would let a dozen such words in a
 *    project's entity list eat the day's listening on answers that will never
 *    come.
 * 2. *`threads_keyword_search` not approved.* Search is then limited to the
 *    project's own posts (§11.2). That is a degraded contour rather than a
 *    broken one, so it is {@see ThreadsSearchOutcome::OwnPostsOnly} and the
 *    adapter goes and reads the project's own posts instead — genuinely working
 *    on less. It is recorded on the integration's `config` rather than in
 *    `failure_reason`, because `failure_reason` means "this connection cannot
 *    answer" and every other caller treats it that way: writing a missing
 *    optional scope there would stop publishing and insights as well. Once
 *    recorded it is not probed again for {@see SCOPE_RECHECK_DAYS} days, so an
 *    hourly listening run does not spend a refused request per keyword per hour
 *    rediscovering the same fact.
 * 3. *A transport failure.* Left as the exception {@see ThreadsClient} already
 *    raises. Keeping it an exception rather than a fourth enum case is what
 *    makes the first two impossible to misread as failure: failure is not a
 *    value this class returns.
 *
 * **The budget.** 2 200 requests per user per 24 h, sliding — see
 * {@see ThreadsSearchBudget}, which also argues its keying and why it is not
 * the publishing limiter. Reaching the ceiling returns
 * {@see ThreadsSearchOutcome::BudgetSpent} rather than throwing: a full window
 * is the budget working, and §5 is explicit that a ceiling is a ceiling and
 * undershooting is allowed.
 *
 * Nothing here writes a `Signal` or an `Interaction`. This is the intake edge;
 * normalisation, dedup against thirty days and the queue, and the hourly
 * orchestration are the `social_listen` pipeline's, and they are a separate
 * step.
 *
 * ⚠️ Written from Meta's published documentation and **not verified against a
 * live account**, like every other Threads class here. The parameter names, the
 * response shape and — most of all — the error the platform returns when the
 * search scope is missing are documented guesses asserted in the suite and
 * proven nowhere else.
 */
final class ThreadsSearch
{
    /** The scope §11.2 says listening degrades without. */
    public const string SCOPE = 'threads_keyword_search';

    /** Where the degradation of §11.2 is recorded on the integration. */
    public const string DEGRADED_FLAG = 'keyword_search_scope';

    /** §2 — the endpoint's own ceiling on one page of results. */
    private const int MAX_LIMIT = 100;

    /**
     * How long a missing scope is believed before the platform is asked again.
     *
     * Two windows, because the two ways of recognising a refusal are not
     * equally good evidence. A 403 is the platform answering the question we
     * asked: seven days, and one probe a week is nothing. A message that merely
     * *reads* like a permission error is a guess about wording we have never
     * seen from a live account (see {@see SCOPE_REFUSAL_HINTS}), and the cost of
     * being wrong is a project that quietly listens to its own posts. So a
     * message match is believed for a day — twenty-four probes a week against
     * §2's 2 200, which is the cheapest self-correction available.
     */
    private const int SCOPE_RECHECK_DAYS = 7;

    private const int WEAK_SCOPE_RECHECK_DAYS = 1;

    /** The platform answered the question we asked: `403`. */
    private const string EVIDENCE_STATUS = 'status';

    /** Recognised only by the wording of the error. Weaker, and rechecked sooner. */
    private const string EVIDENCE_MESSAGE = 'message';

    /** What {@see ThreadsPost} models. Asking for less would mean asking twice. */
    private const string FIELDS = 'id,text,username,permalink,timestamp,media_type';

    /**
     * Meta's wording when an app reaches an endpoint it was not granted.
     *
     * Matched on the message because {@see ThreadsRefused} carries the HTTP
     * status and not the OAuth subcode, and Meta answers a missing permission
     * with a 400 as readily as with a 403.
     *
     * **Phrases, not words.** The list used to hold `permission`, `scope` and
     * `unsupported get request`, on the argument that a false positive costs a
     * week of listening to our own posts where a false negative is an exception
     * an hour forever. Both halves of that were wrong. `Unsupported get
     * request` is Meta's *generic* 400 — a path that does not exist, an object
     * id that does not — and its full text ("cannot be loaded due to missing
     * permissions, or does not support this operation") contains the word
     * `permission` as well, so a mistyped user id degraded the project for a
     * week on the strength of a `notice`. And a false negative is not an
     * exception an hour: {@see FetchSearch::ask()} catches
     * {@see ThreadsRefused} per term, logs it and asks the next one, which is
     * both visible and survivable.
     *
     * So the phrases below are ones the generic refusal does not contain, the
     * `403` branch of {@see isScopeRefusal()} carries the case Meta documents,
     * and anything recognised only by wording is believed for
     * {@see WEAK_SCOPE_RECHECK_DAYS} rather than a week.
     *
     * ⚠️ Still unverified against a live account, like everything else here.
     *
     * @var list<string>
     */
    private const array SCOPE_REFUSAL_HINTS = [
        'does not have permission',
        'requires the following permission',
        'not been approved',
        'insufficient scope',
        self::SCOPE,
    ];

    /**
     * The degraded path's own-posts page, per project and per bound.
     *
     * The one piece of state on this class, and it lives exactly as long as the
     * instance does — nothing binds this as a singleton, so that is one step of
     * one listening run. See {@see ownPosts()} for why it is worth having.
     *
     * @var array<string, list<ThreadsPost>>
     */
    private array $pages = [];

    public function __construct(
        private readonly ThreadsClient $client,
        private readonly ThreadsConnection $connection,
        private readonly ThreadsSearchBudget $budget,
    ) {}

    /**
     * Search Threads on behalf of one project.
     *
     * Every parameter of §2's table is here because §4.1 uses most of them in
     * one hour: `RECENT` and `TAG` passes over the project's entities, `since`
     * bounded to the last run so the same conversation is not re-read all day.
     */
    public function search(
        Project $project,
        string $query,
        ThreadsSearchMode $mode = ThreadsSearchMode::Keyword,
        ThreadsSearchType $type = ThreadsSearchType::Recent,
        ?DateTimeInterface $since = null,
        ?DateTimeInterface $until = null,
        int $limit = 25,
    ): ThreadsSearchAnswer {
        $query = trim($query);

        if ($query === '') {
            // Not an error and not worth a request: an empty term is a caller
            // whose entity list has a blank in it, and the platform would
            // answer nothing anyway.
            return ThreadsSearchAnswer::nothing();
        }

        $integration = $this->connection->for($project);

        if ($integration === null) {
            return ThreadsSearchAnswer::notConnected();
        }

        $token = $this->connection->accessToken($integration);

        if ($token === null) {
            return ThreadsSearchAnswer::notConnected();
        }

        // Checked before the call, so a full window costs nothing at all —
        // including the round trip that would have discovered it.
        if (! $this->budget->allows($project, 1)) {
            return ThreadsSearchAnswer::budgetSpent();
        }

        if ($this->isDegraded($integration)) {
            return $this->ownPosts($project, $integration, $token, $query, $mode, $since, $until, $limit);
        }

        try {
            $answer = $this->client->get('keyword_search', $this->parameters($query, $mode, $type, $since, $until, $limit), $token);
        } catch (ThreadsRefused $e) {
            // Charged. §2 exempts "пустые ответы", and a refusal is not one: the
            // request reached the platform, the platform did work deciding to
            // say no, and there is nothing in the documentation that says it
            // comes free. Which way to guess is settled by §5 — "Бюджет —
            // потолок, а не план. Недобор допустим, перебор — нет." Not
            // charging risks going over a ceiling we cannot read back and
            // collecting 429s the engine reads as an outage; charging risks
            // listening slightly less than we could. The second is the failure
            // §5 permits.
            $this->budget->spend($project, 1);

            $evidence = $this->scopeRefusalEvidence($e);

            if ($evidence === null) {
                // Terminal and not about permissions — a malformed parameter,
                // a term the endpoint will not take, a bad object id. The
                // caller decides; it is not this class's business to swallow
                // it, and {@see FetchSearch} skips the term and asks the next.
                throw $e;
            }

            $this->recordDegraded($project, $integration, $e->getMessage(), $evidence);

            return $this->ownPosts($project, $integration, $token, $query, $mode, $since, $until, $limit);
        }

        // The platform answered a keyword search, so the scope is granted. If
        // we had it recorded as missing, the approval of §11.2 has landed.
        $this->clearDegraded($project, $integration);

        $posts = $this->posts($answer);

        if ($posts === []) {
            // §11.2 and §2 together: not a fault, and not a request that
            // counts. Both halves matter — one of them alone would either
            // retry a word that is silent by design or spend the day's budget
            // discovering that it still is.
            return ThreadsSearchAnswer::nothing();
        }

        $this->budget->spend($project, 1);

        return ThreadsSearchAnswer::searched($posts);
    }

    /**
     * What §11.2 says listening becomes without the scope: our own posts.
     *
     * Read from `GET /{user-id}/threads` rather than by calling
     * `keyword_search` again and hoping. The spec describes the ungranted state
     * as "поиск ограничен собственными постами", and this is that sentence
     * implemented honestly — one call that is always permitted, filtered here
     * for the term, instead of an hourly refusal.
     *
     * The filter is a plain case-insensitive containment test. It is not the
     * platform's ranking and does not pretend to be; the alternative is
     * discarding the term entirely and handing the planner every post the
     * project ever made, every hour.
     *
     * **One page per caller, filtered in memory.** The request does not depend
     * on the term — it is the same `GET /{user-id}/threads` every time, and
     * only the containment test below differs — so a degraded hour used to
     * fetch one identical page sixteen times: eight vocabulary terms in the two
     * modes of §4.1. The page is therefore memoised per bound
     * for the lifetime of this instance, which is one listening step: fifteen
     * requests an hour, 360 a day against §2's 2 200, saved by a keyed array.
     * Not a cache, deliberately — nothing survives the step, so an hour never
     * reads the previous hour's posts.
     *
     * **And it is charged.** The old accounting spent the budget only when the
     * filter matched, which made a degraded project's real requests mostly
     * free: the platform answered with a full page and we discarded it here.
     * §2's exemption is for an *empty answer* — "пустые ответы не считаются" —
     * so the charge follows what the platform sent, not what we kept.
     */
    private function ownPosts(
        Project $project,
        ProjectIntegration $integration,
        string $token,
        string $query,
        ThreadsSearchMode $mode,
        ?DateTimeInterface $since,
        ?DateTimeInterface $until,
        int $limit,
    ): ThreadsSearchAnswer {
        $userId = $this->connection->userId($integration);

        if ($userId === null) {
            return ThreadsSearchAnswer::ownPostsOnly([]);
        }

        $parameters = ['fields' => self::FIELDS, 'limit' => $this->limit($limit)];

        if ($since !== null) {
            $parameters['since'] = $this->date($since);
        }

        if ($until !== null) {
            $parameters['until'] = $this->date($until);
        }

        $key = implode('|', [$project->getKey(), $userId, ...array_values($parameters)]);

        if (! array_key_exists($key, $this->pages)) {
            $page = $this->posts($this->client->get("{$userId}/threads", $parameters, $token));

            if ($page !== []) {
                // Charged even though this is not `keyword_search`. §2 states
                // one number for the account's reads and no separate allowance
                // for this endpoint, and under-spending a budget is the failure
                // §5 permits. Charged on what came back rather than on what
                // survives the filter below, because that is what the platform
                // counted.
                $this->budget->spend($project, 1);
            }

            $this->pages[$key] = $page;
        }

        $needle = $mode === ThreadsSearchMode::Tag ? '#'.ltrim($query, '#') : $query;

        $posts = array_values(array_filter(
            $this->pages[$key],
            static fn (ThreadsPost $post): bool => mb_stripos($post->text, $needle) !== false,
        ));

        return ThreadsSearchAnswer::ownPostsOnly($posts);
    }

    /**
     * Whether we already know the scope of §11.2 is missing.
     *
     * Two sources, both cheap. The recorded flag is what a refusal taught us
     * and it expires, so an approval that arrives later is noticed within a
     * week rather than never. The granted scope list is what the operator's
     * OAuth callback stored, and it is only consulted when it is non-empty — a
     * connection made before scopes were recorded has an empty list, and
     * reading that as "nothing granted" would silently degrade a project whose
     * scope is fine.
     */
    private function isDegraded(ProjectIntegration $integration): bool
    {
        $flag = $integration->config[self::DEGRADED_FLAG] ?? null;

        if (is_array($flag) && ($flag['granted'] ?? null) === false) {
            $observedAt = $this->parse($flag['observed_at'] ?? null);

            // Graded by how the refusal was recognised. A flag written before
            // this distinction existed, or by the OAuth callback — which reads
            // the granted scope list rather than guessing at wording — carries
            // no `evidence` and gets the long window.
            $window = ($flag['evidence'] ?? null) === self::EVIDENCE_MESSAGE
                ? self::WEAK_SCOPE_RECHECK_DAYS
                : self::SCOPE_RECHECK_DAYS;

            if ($observedAt === null || $observedAt->addDays($window)->isFuture()) {
                return true;
            }

            // The recheck window has passed. Fall through and let the platform
            // answer: the approval of §11.2 is a thing that happens once, and
            // nothing else would ever tell us it had.
            return false;
        }

        $scopes = $integration->scopes;

        return $scopes !== [] && ! in_array(self::SCOPE, $scopes, true);
    }

    /**
     * How — if at all — a terminal refusal says "you were not granted this"
     * rather than "this request is wrong".
     *
     * Returns the strength of the evidence rather than a boolean, because the
     * two ways of recognising the same refusal are believed for different
     * lengths of time. See {@see SCOPE_RECHECK_DAYS}.
     */
    private function scopeRefusalEvidence(ThreadsRefused $e): ?string
    {
        if ($e->status === 403) {
            return self::EVIDENCE_STATUS;
        }

        $message = mb_strtolower($e->getMessage());

        foreach (self::SCOPE_REFUSAL_HINTS as $hint) {
            if (str_contains($message, $hint)) {
                return self::EVIDENCE_MESSAGE;
            }
        }

        return null;
    }

    /**
     * Write the degradation where an operator can see it, once.
     *
     * On `config` rather than on `failure_reason`, which is reserved for a
     * connection that cannot answer at all — putting a missing optional scope
     * there would make {@see ThreadsConnection::isUsable()} false and stop
     * publishing and insights along with it, which is the opposite of the
     * "работает на меньшем" §11.2 describes.
     *
     * Logged at notice level and only when the flag changes, so the operator
     * gets one line the day the scope goes missing rather than one an hour.
     */
    private function recordDegraded(
        Project $project,
        ProjectIntegration $integration,
        string $reason,
        string $evidence,
    ): void {
        $config = $integration->config;
        $config[self::DEGRADED_FLAG] = [
            'granted' => false,
            'observed_at' => Carbon::now()->utc()->toIso8601String(),
            'reason' => $reason,
            // How sure we are, so {@see isDegraded()} knows how long to believe
            // it. Stored rather than recomputed: the exception is gone by the
            // time the flag is read again, an hour later on another worker.
            'evidence' => $evidence,
        ];

        $integration->forceFill(['config' => $config])->save();

        Log::notice('Threads keyword search is not approved for this project; listening is limited to its own posts', [
            'project' => $project->getKey(),
            'integration' => $integration->getKey(),
            'scope' => self::SCOPE,
            'reason' => $reason,
            'evidence' => $evidence,
        ]);
    }

    /** The approval landed. Stop reporting a degradation that is over. */
    private function clearDegraded(Project $project, ProjectIntegration $integration): void
    {
        $config = $integration->config;

        if (! array_key_exists(self::DEGRADED_FLAG, $config)) {
            return;
        }

        unset($config[self::DEGRADED_FLAG]);

        $integration->forceFill(['config' => $config])->save();

        Log::info('Threads keyword search is approved again; listening is no longer limited to our own posts', [
            'project' => $project->getKey(),
            'integration' => $integration->getKey(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(
        string $query,
        ThreadsSearchMode $mode,
        ThreadsSearchType $type,
        ?DateTimeInterface $since,
        ?DateTimeInterface $until,
        int $limit,
    ): array {
        $parameters = [
            'q' => $query,
            'search_mode' => $mode->value,
            'search_type' => $type->value,
            'fields' => self::FIELDS,
            'limit' => $this->limit($limit),
        ];

        if ($since !== null) {
            $parameters['since'] = $this->date($since);
        }

        if ($until !== null) {
            $parameters['until'] = $this->date($until);
        }

        return $parameters;
    }

    /** §2 caps a page at 100, and asking for more is a refusal, not more. */
    private function limit(int $limit): int
    {
        return max(1, min($limit, self::MAX_LIMIT));
    }

    /**
     * ⚠️ `since` and `until` are documented as dates rather than instants, and
     * in UTC — the same trap `Signal`'s docblock spells out for bound
     * timestamps. Converting explicitly means an hourly run in a project on
     * `Europe/Lisbon` asks for the day the platform means.
     */
    private function date(DateTimeInterface $at): string
    {
        return Carbon::instance($at)->utc()->format('Y-m-d');
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return list<ThreadsPost>
     */
    private function posts(array $answer): array
    {
        $data = $answer['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        $posts = [];

        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $post = ThreadsPost::fromApi($row);

            if ($post !== null) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
