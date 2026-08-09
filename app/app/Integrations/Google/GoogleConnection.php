<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use App\Enums\IntegrationProvider;
use App\Integrations\Exceptions\ConnectionRevoked;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Support\Tenancy\CurrentProject;

/**
 * The project's Google connection, and a live access token for it.
 *
 * Every caller that talks to a Google API comes through here rather than
 * reading the token off the model, so "is it still valid, and if not get
 * another one" is answered in one place. A step that refreshes its own token is
 * a step that will one day forget to persist it and refresh on every call.
 */
class GoogleConnection
{
    public function __construct(
        private readonly GoogleOAuth $oauth,
        private readonly CurrentProject $current,
    ) {}

    /** The connection for a project, or for whichever project is current. */
    public function for(?Project $project = null): ?ProjectIntegration
    {
        $find = fn (): ?ProjectIntegration => ProjectIntegration::query()
            ->where('provider', IntegrationProvider::Google->value)
            ->first();

        return $project === null ? $find() : $this->current->run($project, $find);
    }

    /**
     * A token that will still be valid when the next request goes out.
     *
     * Returns null when there is nothing to work with — no connection, or one
     * Google has stopped honouring. Callers treat that as "this project is not
     * connected", which is a different thing from "the request failed".
     */
    public function accessToken(ProjectIntegration $integration): ?string
    {
        if (! $integration->isUsable()) {
            return null;
        }

        $current = $integration->access_token;

        if ($current !== null && $integration->access_token_expires_at?->isFuture() === true) {
            return $current;
        }

        $refreshToken = $integration->refresh_token;

        if ($refreshToken === null) {
            return null;
        }

        try {
            $grant = $this->oauth->refresh($refreshToken);
        } catch (ConnectionRevoked $e) {
            // Recorded on the row, so the settings screen can say "reconnect"
            // instead of every scheduled run failing the same way until
            // somebody reads the logs.
            $integration->markBroken($e->getMessage());

            return null;
        }

        $integration->forceFill([
            'access_token' => $grant['access_token'],
            'access_token_expires_at' => $grant['expires_at'],
            // Google usually omits it on a refresh; when it does send one, the
            // old one may already be dead.
            'refresh_token' => $grant['refresh_token'] ?? $refreshToken,
            'failure_reason' => null,
        ])->save();

        return $grant['access_token'];
    }
}
