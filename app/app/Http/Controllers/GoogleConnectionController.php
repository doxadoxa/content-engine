<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Integrations\Exceptions\ConnectionRevoked;
use App\Integrations\Exceptions\GoogleUnavailable;
use App\Integrations\Google\GoogleConnection;
use App\Integrations\Google\GoogleOAuth;
use App\Integrations\Google\GoogleProperties;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Connecting a project to Search Console and GA4.
 *
 * One consent screen for both, because they are one question to the person
 * answering it — "may this thing read how my site is doing" — and two would
 * double the chance of ending up with half a connection.
 *
 * The project is remembered in the session rather than carried in the redirect
 * URI, because Google matches redirect URIs as exact strings: a per-project URI
 * would have to be registered per project.
 */
class GoogleConnectionController extends Controller
{
    private const string SESSION_STATE = 'google.oauth.state';

    private const string SESSION_VERIFIER = 'google.oauth.verifier';

    private const string SESSION_PROJECT = 'google.oauth.project';

    public function __construct(
        private readonly GoogleOAuth $oauth,
        private readonly GoogleConnection $connection,
        private readonly GoogleProperties $properties,
        private readonly CurrentProject $current,
    ) {}

    /** Send the operator to Google. */
    public function connect(Request $request, Project $project): RedirectResponse
    {
        $this->authorise($request, $project);

        if (! $this->oauth->isConfigured()) {
            return $this->back($project, 'error', 'Google is not set up on this installation yet.');
        }

        $grant = $this->oauth->authorisationUrl([
            ProjectIntegration::SCOPE_SEARCH_CONSOLE,
            ProjectIntegration::SCOPE_ANALYTICS,
        ]);

        // All three in the session: the state to compare on the way back, the
        // verifier that proves this browser started it, and which project asked.
        $request->session()->put(self::SESSION_STATE, $grant['state']);
        $request->session()->put(self::SESSION_VERIFIER, $grant['verifier']);
        $request->session()->put(self::SESSION_PROJECT, $project->getKey());

        return redirect()->away($grant['url']);
    }

    /**
     * Back from Google.
     *
     * Every branch below forgets the session keys before doing anything else: a
     * verifier left behind is a one-time secret that stops being one.
     */
    public function callback(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $expected = $request->session()->pull(self::SESSION_STATE);
        $verifier = $request->session()->pull(self::SESSION_VERIFIER);
        $projectId = $request->session()->pull(self::SESSION_PROJECT);

        $project = is_string($projectId)
            ? $user->projects()->whereKey($projectId)->wherePivot('role', 'owner')->first()
            : null;

        if ($project === null) {
            return $this->toProjects('We lost track of which project you were connecting. Try again.');
        }

        // Constant-time, and null-safe: a callback with no state in session is
        // one nobody here started, which is exactly the request this check is
        // for.
        $given = $request->query('state');

        if (! is_string($expected) || ! is_string($given) || ! hash_equals($expected, $given)) {
            return $this->back($project, 'error', 'That sign-in did not come from here. Nothing was connected.');
        }

        if ($request->query('error') !== null) {
            // The operator pressed cancel, most often. Not an error worth a red
            // banner, and definitely not worth a stack trace.
            return $this->back($project, 'info', 'Google was not connected.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '' || ! is_string($verifier)) {
            return $this->back($project, 'error', 'Google sent us back without an authorisation code.');
        }

        try {
            $grant = $this->oauth->exchange($code, $verifier);
        } catch (ConnectionRevoked|GoogleUnavailable $e) {
            // The message is Google's and safe to show; the code is not, so
            // nothing about the exchange goes to the log beyond which project.
            Log::warning('A Google connection could not be completed', [
                'project' => $project->slug,
                'reason' => $e->getMessage(),
            ]);

            return $this->back($project, 'error', $e->getMessage());
        }

        if ($grant['refresh_token'] === null) {
            // Without one we can read for an hour and then stop, which looks
            // like a working connection right up until it is not.
            return $this->back(
                $project,
                'error',
                'Google did not give us lasting access. Remove this app at myaccount.google.com and connect again.',
            );
        }

        $this->store($project, $user, $grant);

        return $this->back($project, 'success', 'Google connected. Choose which properties to read.');
    }

    /**
     * Which Search Console site and which GA4 property to read.
     *
     * Chosen from the real lists rather than typed: Search Console names a site
     * `sc-domain:example.com` or `https://example.com/` — trailing slash and
     * all — and a value that is nearly right returns nothing rather than an
     * error.
     */
    public function choose(Request $request, Project $project): RedirectResponse
    {
        $this->authorise($request, $project);

        $integration = $this->connection->for($project);

        abort_if($integration === null, 404);

        $validated = $request->validate([
            'search_console_site' => ['nullable', 'string', 'max:255'],
            'analytics_property' => ['nullable', 'string', 'max:255'],
        ]);

        // Checked against what this account can actually see, so a crafted
        // request cannot point one project at another account's property. The
        // lists come from Google, not from the form.
        try {
            $sites = $this->properties->searchConsoleSites($integration);
            $analytics = $this->properties->analyticsProperties($integration);
        } catch (GoogleUnavailable $e) {
            // Without the lists there is nothing to check the selection
            // against, and saving it unchecked is the one thing this must not
            // do. Better to ask for a retry than to store a value nobody
            // verified.
            return $this->back($project, 'error', 'Google did not answer just now. Try saving again in a moment.');
        }

        $site = $this->chosen($validated['search_console_site'] ?? null, $sites);
        $property = $this->chosen($validated['analytics_property'] ?? null, $analytics);

        $this->current->run($project, static function () use ($integration, $site, $property): void {
            $integration->forceFill([
                'config' => [
                    ...$integration->config,
                    'search_console_site' => $site,
                    'analytics_property' => $property,
                ],
            ])->save();
        });

        return $this->back($project, 'success', 'Saved. The next feedback run will read from these.');
    }

    /** Forget the grant, at Google and here. */
    public function disconnect(Request $request, Project $project): RedirectResponse
    {
        $this->authorise($request, $project);

        $integration = $this->connection->for($project);

        if ($integration !== null) {
            // Revoked first, deleted regardless: an operator who asked to
            // disconnect must not be left connected because Google's revoke
            // endpoint was down.
            $token = $integration->refresh_token;

            if ($token !== null) {
                $this->oauth->revoke($token);
            }

            $this->current->run($project, static fn () => $integration->delete());
        }

        return $this->back($project, 'success', 'Google disconnected.');
    }

    /**
     * @param  list<array{value: string, label: string}>  $options
     */
    private function chosen(?string $value, array $options): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        foreach ($options as $option) {
            if ($option['value'] === $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array{refresh_token: string|null, access_token: string, expires_at: Carbon, scopes: list<string>}  $grant
     */
    private function store(Project $project, User $user, array $grant): void
    {
        $this->current->run($project, function () use ($user, $grant): void {
            ProjectIntegration::query()->updateOrCreate(
                ['provider' => IntegrationProvider::Google->value],
                [
                    'refresh_token' => $grant['refresh_token'],
                    'access_token' => $grant['access_token'],
                    'access_token_expires_at' => $grant['expires_at'],
                    // What was granted, not what was asked for: the consent
                    // screen lets one of the two be unticked, and a feature
                    // that reads this can decline to offer itself rather than
                    // failing with a 403 an hour later.
                    'scopes' => $grant['scopes'],
                    'connected_by_id' => $user->getKey(),
                    'connected_at' => now(),
                    'failure_reason' => null,
                ],
            );
        });
    }

    private function authorise(Request $request, Project $project): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->projects()->whereKey($project->getKey())->wherePivot('role', 'owner')->exists(),
            404,
        );
    }

    private function back(Project $project, string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('projects.edit', $project);
    }

    private function toProjects(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('projects.index');
    }
}
