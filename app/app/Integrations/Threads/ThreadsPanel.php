<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use App\Integrations\Google\GooglePanel;
use App\Models\Project;
use App\Models\ProjectIntegration;

/**
 * Everything the settings screen needs to say about the Threads connection.
 *
 * The same four states {@see GooglePanel} describes — not set up, not
 * connected, broken, connected — assembled here rather than in the controller
 * so that a second screen asking the same question cannot end up with a
 * different answer.
 *
 * Two things are said here that Google's panel has no equivalent of:
 *
 * - **when the token expires.** Google's connection is held open by a refresh
 *   token that does not expire, so there is no date worth showing. A Threads
 *   long-lived token dies at ~60 days and is kept alive by `threads:renew`
 *   walking the projects nightly. Printing the date is how an operator can see
 *   that the renewal is actually happening rather than discovering it did not.
 * - **whether keyword search was granted.** §11.2 makes that approval separate,
 *   and a project without it still publishes, still listens, and simply hears
 *   less. That is worth a sentence and is not worth the broken state.
 *
 * Nothing here talks to Meta. The Google panel lists properties and so has to,
 * which is why it is deferred; this one reads a row, and a settings screen that
 * blocks on somebody else's API to say "connected" is the thing the deferral
 * exists to avoid.
 */
class ThreadsPanel
{
    public function __construct(
        private readonly ThreadsOAuth $oauth,
        private readonly ThreadsConnection $connection,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function panelFor(Project $project): array
    {
        if (! $this->oauth->isConfigured()) {
            return [
                'state' => 'unavailable',
                'reason' => 'This installation has no Threads app configured.',
            ];
        }

        $integration = $this->connection->for($project);

        if ($integration === null) {
            return ['state' => 'disconnected'];
        }

        // Asked of {@see ThreadsConnection} rather than of the model, because
        // `ProjectIntegration::isUsable()` looks for a refresh token and this
        // provider has none — a Threads row would read as broken from the day
        // it was connected.
        if (! $this->connection->isUsable($integration)) {
            return [
                'state' => 'broken',
                'reason' => $integration->failure_reason,
                'connected_at' => $integration->connected_at?->toIso8601String(),
                'username' => $this->username($integration),
            ];
        }

        return [
            'state' => 'connected',
            'username' => $this->username($integration),
            'user_id' => $this->connection->userId($integration),
            'connected_at' => $integration->connected_at?->toIso8601String(),
            'connected_by' => $integration->connectedBy?->name,
            'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
            'expires_at' => $integration->access_token_expires_at?->toIso8601String(),
            'grants_keyword_search' => $this->grantsKeywordSearch($integration),
        ];
    }

    private function username(ProjectIntegration $integration): ?string
    {
        $username = $integration->config['username'] ?? null;

        return is_string($username) && $username !== '' ? $username : null;
    }

    /**
     * Whether listening is running at full strength.
     *
     * Both sources are consulted, because they answer at different times. The
     * scope list is what the consent screen returned; the config flag is what
     * {@see ThreadsSearch} learned later from a refusal, which is the only way
     * an installation whose token endpoint says nothing about permissions ever
     * finds out.
     */
    private function grantsKeywordSearch(ProjectIntegration $integration): bool
    {
        $flag = $integration->config[ThreadsSearch::DEGRADED_FLAG] ?? null;

        if (is_array($flag) && ($flag['granted'] ?? null) === false) {
            return false;
        }

        $scopes = $integration->scopes;

        return $scopes === [] || in_array(ThreadsSearch::SCOPE, $scopes, true);
    }
}
