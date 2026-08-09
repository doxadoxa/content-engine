<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use App\Integrations\Exceptions\ThreadsCredentialRejected;
use App\Integrations\Exceptions\ThreadsRefused;
use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\ProjectState;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * The account's own numbers, a day at a time (§6).
 *
 * The third source of §6, alongside Search Console and GA4, and the only one
 * that is about the channel rather than about the site: followers, and the five
 * things people did to what we posted. `threads_manage_insights` is already in
 * the scopes the OAuth flow asks for, so nothing new is requested of the
 * operator — but a scope requested is not a scope granted, and this class is
 * mostly about that gap.
 *
 * **Every failure is a null and none of them is an exception.** The caller is a
 * daily snapshot with one row per project, and the three things that go wrong
 * here — no connection, a permission Meta never approved for this app, a
 * platform having a bad hour — are all "we did not measure the channel today".
 * A zero would be a lie the governor acts on: §4.3 cuts a project's publishing
 * frequency when the trailing reply rate is low, and a run of zeroed
 * impressions from an unapproved permission would throttle a healthy account
 * down to two posts a week with no way for anyone to see why. Null is refused
 * by {@see ProjectState::replyRate()} one layer down, which is
 * exactly the behaviour wanted.
 *
 * Two requests rather than one. The time-series metrics take a window;
 * `followers_count` is a lifetime total and the platform rejects it in the same
 * call as a `since`/`until`. Splitting them also means a project whose follower
 * metric is refused still gets its post metrics.
 *
 * ⚠️ Written from Meta's published documentation and **not verified against a
 * live account**, exactly like {@see ThreadsClient} and the Google adapters.
 * The metric names, the two response shapes below and the permission the
 * platform demands for each are all unproven until someone runs this against a
 * real token.
 */
class ThreadsInsights
{
    /**
     * What one day's read asks for.
     *
     * `views` is the platform's word for impressions. The other four are the
     * reactions §6 tabulates, and `replies` is the one §4.3 divides by `views`.
     */
    public const string METRICS = 'views,likes,replies,reposts,quotes';

    /** A lifetime total with no history, which is why it travels alone. */
    public const string FOLLOWERS_METRIC = 'followers_count';

    public function __construct(
        private readonly ThreadsClient $client,
        private readonly ThreadsConnection $connection,
    ) {}

    /**
     * One day of the account's insights, or null if it could not be measured.
     *
     * The day is bounded in the project's timezone, because that is what
     * `project_states.captured_on` means and a snapshot whose window is a UTC
     * day would file eight hours of Lisbon's Tuesday under Monday.
     */
    public function forDay(Project $project, CarbonInterface $day): ?ThreadsChannelHealth
    {
        $integration = $this->connection->for($project);

        if ($integration === null) {
            return null;
        }

        $token = $this->connection->accessToken($integration);
        $userId = $this->connection->userId($integration);

        if ($token === null || $userId === null) {
            return null;
        }

        $start = $day->copy()->setTimezone($project->timezone)->startOfDay();

        $metrics = $this->read($integration, $userId, $token, [
            'metric' => self::METRICS,
            'since' => $start->getTimestamp(),
            'until' => $start->copy()->addDay()->getTimestamp(),
        ]);

        if ($metrics === null) {
            return null;
        }

        $followers = $this->read($integration, $userId, $token, [
            'metric' => self::FOLLOWERS_METRIC,
        ]);

        $integration->forceFill(['last_synced_at' => now()])->save();

        // `?? null` and never `?? 0`, one metric at a time. A response can be
        // partial for reasons that have nothing to do with the account —
        // `threads_manage_insights` is approved per metric, `data` comes back
        // with whichever entries the platform was willing to serve, and a
        // renamed metric arrives as an absent one. Defaulting to zero turned
        // any of those into "nobody replied to us today" beside a real
        // impression count, and §4.3 throttles a project on exactly that ratio.
        // Absent is not zero; it is the null the rest of this class is built
        // out of.
        return new ThreadsChannelHealth(
            followers: $followers === null ? null : ($followers[self::FOLLOWERS_METRIC] ?? null),
            impressions: $metrics['views'] ?? null,
            replies: $metrics['replies'] ?? null,
            likes: $metrics['likes'] ?? null,
            reposts: $metrics['reposts'] ?? null,
            quotes: $metrics['quotes'] ?? null,
        );
    }

    /**
     * One insights call, flattened to metric => value, or null if it failed.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, int>|null
     */
    private function read(
        ProjectIntegration $integration,
        string $userId,
        string $token,
        array $query,
    ): ?array {
        try {
            $answer = $this->client->get("{$userId}/threads_insights", $query, $token);
        } catch (ThreadsCredentialRejected $e) {
            // The one failure that outlives the request. Said out loud on the
            // integration, because only a human reconnecting fixes it and the
            // settings screen is where they will look.
            $integration->markBroken("Threads refused the connection: {$e->getMessage()} Reconnect to grant access again.");

            return null;
        } catch (ThreadsRefused $e) {
            // The unapproved case, and the reason this whole class returns
            // nulls: Meta refuses `threads_manage_insights` for an app that has
            // not been through review, per account and without warning. That is
            // a permanent state of the installation rather than an incident,
            // and it must degrade to "not measured" every day it lasts.
            Log::warning('Threads refused an insights read', [
                'integration' => $integration->getKey(),
                'metric' => $query['metric'] ?? null,
                'reason' => $e->getMessage(),
            ]);

            return null;
        } catch (ThreadsUnavailable $e) {
            // Transient, and still a null. The snapshot is idempotent on
            // (project_id, captured_on) and re-reads a trailing window of days,
            // so tomorrow's run fills today's gap in — which is a better answer
            // than failing a sweep that has other projects to visit.
            Log::warning('Threads did not answer an insights read', [
                'integration' => $integration->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->flatten($answer);
    }

    /**
     * Metric name => number, out of either shape the endpoint answers in.
     *
     * The platform serves time-series metrics as `values: [{value, end_time}]`
     * and totals as `total_value: {value}`, and which metric is which is
     * documented per metric rather than per request. Reading both shapes costs
     * four lines and removes a class of silent zero — a metric that quietly
     * switched shape would otherwise read as "nothing happened today", which
     * is the exact failure this class exists to avoid.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, int>
     */
    private function flatten(array $answer): array
    {
        $data = is_array($answer['data'] ?? null) ? $answer['data'] : [];
        $flat = [];

        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = $entry['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $values = $entry['values'] ?? null;

            if (is_array($values) && $values !== []) {
                $total = 0;

                foreach ($values as $value) {
                    $total += is_array($value) && is_numeric($value['value'] ?? null)
                        ? (int) $value['value']
                        : 0;
                }

                $flat[$name] = $total;

                continue;
            }

            $total = $entry['total_value'] ?? null;

            if (is_array($total) && is_numeric($total['value'] ?? null)) {
                $flat[$name] = (int) $total['value'];
            }
        }

        return $flat;
    }
}
