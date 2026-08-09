<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use App\Integrations\Exceptions\GoogleUnavailable;
use App\Models\Project;
use App\Models\ProjectIntegration;
use Illuminate\Support\Facades\Log;

/**
 * Everything the settings screen needs to say about the Google connection.
 *
 * Assembled here rather than in the controller because the same four states —
 * not set up, not connected, broken, connected — are the ones the dashboard
 * prompt asks about too, and describing them twice is how the two screens end
 * up disagreeing about whether a project is connected.
 */
class GooglePanel
{
    public function __construct(
        private readonly GoogleOAuth $oauth,
        private readonly GoogleConnection $connection,
        private readonly GoogleProperties $properties,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function panelFor(Project $project): array
    {
        if (! $this->oauth->isConfigured()) {
            return [
                'state' => 'unavailable',
                'reason' => 'This installation has no Google OAuth client configured.',
            ];
        }

        $integration = $this->connection->for($project);

        if ($integration === null) {
            return ['state' => 'disconnected'];
        }

        if (! $integration->isUsable()) {
            return [
                'state' => 'broken',
                'reason' => $integration->failure_reason,
                'connected_at' => $integration->connected_at?->toIso8601String(),
            ];
        }

        return [
            'state' => 'connected',
            'connected_at' => $integration->connected_at?->toIso8601String(),
            'connected_by' => $integration->connectedBy?->name,
            'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
            'grants_search_console' => $integration->grants(ProjectIntegration::SCOPE_SEARCH_CONSOLE),
            'grants_analytics' => $integration->grants(ProjectIntegration::SCOPE_ANALYTICS),
            'search_console_site' => $integration->searchConsoleSite(),
            'analytics_property' => $integration->analyticsProperty(),
            ...$this->options($project, $integration),
        ];
    }

    /**
     * Which halves of Google this project can actually be read from.
     *
     * Asked of the connection rather than of the feedback gateways: those are
     * ports with a fake behind them in tests, and a fake that answers "yes" so
     * pipeline tests stay short would have the dashboard claiming a connection
     * that does not exist.
     *
     * @return array{search_console: bool, analytics: bool}
     */
    public function connectionState(Project $project): array
    {
        $integration = $this->connection->for($project);

        if ($integration === null || ! $integration->isUsable()) {
            return ['search_console' => false, 'analytics' => false];
        }

        return [
            'search_console' => $integration->grants(ProjectIntegration::SCOPE_SEARCH_CONSOLE)
                && $integration->searchConsoleSite() !== null,
            'analytics' => $integration->grants(ProjectIntegration::SCOPE_ANALYTICS)
                && $integration->analyticsProperty() !== null,
        ];
    }

    /**
     * The lists to choose from, and a guess at the right answer.
     *
     * Google being slow or down here degrades the panel to "connected, and we
     * could not list your properties" rather than taking the settings page with
     * it — the form above this panel has nothing to do with Google.
     *
     * @return array<string, mixed>
     */
    private function options(Project $project, ProjectIntegration $integration): array
    {
        try {
            $sites = $this->properties->searchConsoleSites($integration);
            $analytics = $this->properties->analyticsProperties($integration);
        } catch (GoogleUnavailable $e) {
            Log::warning('Could not list Google properties', [
                'project' => $project->slug,
                'reason' => $e->getMessage(),
            ]);

            return ['sites' => [], 'properties' => [], 'listing_failed' => true];
        }

        return [
            'sites' => $sites,
            'properties' => $analytics,
            'listing_failed' => false,
            // Offered as a preselection, never as a decision: being wrong costs
            // a click, and being silently wrong would cost a month of somebody
            // else's data.
            'suggested_site' => $integration->searchConsoleSite()
                ?? $this->properties->matching($sites, $project->website_url),
            'suggested_property' => $integration->analyticsProperty()
                ?? $this->properties->matching($analytics, $project->website_url),
        ];
    }
}
