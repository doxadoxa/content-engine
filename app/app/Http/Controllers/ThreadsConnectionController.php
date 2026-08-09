<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Integrations\Exceptions\ThreadsCredentialRejected;
use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Integrations\Threads\ThreadsConnection;
use App\Integrations\Threads\ThreadsOAuth;
use App\Integrations\Threads\ThreadsSearch;
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
 * Connecting a project to a Threads account (§9).
 *
 * The counterpart of {@see GoogleConnectionController}, and arranged the same
 * way for the same reasons: the project is remembered in the session rather
 * than carried in the redirect URI, because Meta compares redirect URIs as
 * exact strings just as Google does, and a per-project URI would have to be
 * registered per project.
 *
 * One grant covers both contours. §4.1's listening and §9's publishing are two
 * uses of one token, and asking twice would leave a project able to post and
 * unable to hear the replies.
 *
 * Where the pieces of the grant land, all on `project_integrations`:
 *
 * - the long-lived token in `access_token`, its ~60-day expiry in
 *   `access_token_expires_at`, and `refresh_token` deliberately null — see
 *   {@see ThreadsConnection}, which explains at length why this provider has
 *   one credential where Google has two;
 * - the Threads user id and username in `config`, because every publishing
 *   path addresses `/{user-id}/threads` and the settings screen has to be able
 *   to say *which* account is connected;
 * - the granted scopes in `scopes`, which is not the same list as the one
 *   asked for: §11.2 says `threads_keyword_search` is approved separately by
 *   Meta, and {@see ThreadsSearch} degrades listening to the project's own
 *   posts when it is missing rather than failing.
 */
class ThreadsConnectionController extends Controller
{
    private const string SESSION_STATE = 'threads.oauth.state';

    private const string SESSION_PROJECT = 'threads.oauth.project';

    public function __construct(
        private readonly ThreadsOAuth $oauth,
        private readonly ThreadsConnection $connection,
        private readonly CurrentProject $current,
    ) {}

    /** Send the operator to Meta. */
    public function connect(Request $request, Project $project): RedirectResponse
    {
        $this->authorise($request, $project);

        if (! $this->oauth->isConfigured()) {
            // Said rather than crashed: an installation with no Meta app is a
            // configuration gap somebody can close, and the settings screen
            // already reports it in place of offering this button.
            return $this->back($project, 'error', 'Threads is not set up on this installation yet.');
        }

        $grant = $this->oauth->authorisationUrl();

        $request->session()->put(self::SESSION_STATE, $grant['state']);
        $request->session()->put(self::SESSION_PROJECT, $project->getKey());

        return redirect()->away($grant['url']);
    }

    /**
     * Back from Meta.
     *
     * Every branch pulls the session keys before doing anything else: a state
     * left behind is a one-time secret that stops being one.
     */
    public function callback(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $expected = $request->session()->pull(self::SESSION_STATE);
        $projectId = $request->session()->pull(self::SESSION_PROJECT);

        $project = is_string($projectId)
            ? $user->projects()->whereKey($projectId)->wherePivot('role', 'owner')->first()
            : null;

        if ($project === null) {
            return $this->toProjects('We lost track of which project you were connecting. Try again.');
        }

        // Constant-time and null-safe: a callback with no state in session is
        // one nobody here started, which is exactly the request this is for.
        $given = $request->query('state');

        if (! is_string($expected) || ! is_string($given) || ! hash_equals($expected, $given)) {
            return $this->back($project, 'error', 'That sign-in did not come from here. Nothing was connected.');
        }

        if ($request->query('error') !== null || $request->query('error_type') !== null) {
            // Cancel, most often. Not worth a red banner and definitely not
            // worth a stack trace.
            return $this->back($project, 'info', 'Threads was not connected.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->back($project, 'error', 'Threads sent us back without an authorisation code.');
        }

        // Meta appends a literal `#_` to the redirect it sends back. As a
        // fragment a browser keeps it to itself, but it has been seen arriving
        // inside the parameter, and a code presented with it on the end is
        // refused. Two characters exactly, so a code that legitimately ends in
        // an underscore survives.
        if (str_ends_with($code, '#_')) {
            $code = substr($code, 0, -2);
        }

        try {
            $grant = $this->oauth->exchange($code);
        } catch (ThreadsCredentialRejected|ThreadsUnavailable $e) {
            // The message is Meta's and safe to show; the code is not, so
            // nothing about the exchange reaches the log beyond which project.
            Log::warning('A Threads connection could not be completed', [
                'project' => $project->slug,
                'reason' => $e->getMessage(),
            ]);

            return $this->back($project, 'error', $e->getMessage());
        }

        $this->store($project, $user, $grant);

        return $this->back($project, 'success', $this->confirmation($grant));
    }

    /**
     * Forget the grant.
     *
     * Nothing is revoked at Meta, unlike Google's disconnect: the Threads API
     * publishes no revoke endpoint, and the only way to withdraw an app's
     * access is the account holder removing it in their own Threads settings.
     * Deleting our copy is the whole of what this side controls, and it is the
     * part that matters — no token here is no posting from here.
     */
    public function disconnect(Request $request, Project $project): RedirectResponse
    {
        $this->authorise($request, $project);

        $integration = $this->connection->for($project);

        if ($integration !== null) {
            $this->current->run($project, static fn () => $integration->delete());
        }

        return $this->back($project, 'success', 'Threads disconnected.');
    }

    /**
     * @param  array{access_token: string, expires_at: Carbon, scopes: list<string>, user_id: string, username: string|null}  $grant
     */
    private function store(Project $project, User $user, array $grant): void
    {
        $this->current->run($project, function () use ($user, $grant): void {
            ProjectIntegration::query()->updateOrCreate(
                ['provider' => IntegrationProvider::Threads->value],
                [
                    // Null on purpose. There is no second credential to hold:
                    // a Threads long-lived token renews by presenting itself.
                    'refresh_token' => null,
                    'access_token' => $grant['access_token'],
                    'access_token_expires_at' => $grant['expires_at'],
                    'scopes' => $grant['scopes'],
                    'config' => $this->config($grant),
                    'connected_by_id' => $user->getKey(),
                    'connected_at' => now(),
                    // Reconnecting is what an operator does when it broke, so
                    // the failure has to clear or the screen keeps telling
                    // them to do it again.
                    'failure_reason' => null,
                ],
            );
        });
    }

    /**
     * What the integration's `config` holds after a connection.
     *
     * Rewritten wholesale rather than merged, which is what makes reconnecting
     * the cure for a stale degradation: §11.2's `threads_keyword_search`
     * approval arrives once, and a project whose config still said "not
     * granted" would go on listening only to its own posts for a week after the
     * grant that fixed it. The flag's shape is {@see ThreadsSearch}'s to define
     * — `granted`, `observed_at`, `reason` — and is written here only in the
     * case the consent screen has already settled: the scope was asked for and
     * is not in what came back.
     *
     * @param  array{access_token: string, expires_at: Carbon, scopes: list<string>, user_id: string, username: string|null}  $grant
     * @return array<string, mixed>
     */
    private function config(array $grant): array
    {
        $config = [
            'user_id' => $grant['user_id'],
            'username' => $grant['username'],
        ];

        if (! in_array(ThreadsSearch::SCOPE, $grant['scopes'], true)) {
            $config[ThreadsSearch::DEGRADED_FLAG] = [
                'granted' => false,
                'observed_at' => Carbon::now()->utc()->toIso8601String(),
                'reason' => 'The consent that connected this account did not include '.ThreadsSearch::SCOPE.'.',
            ];
        }

        return $config;
    }

    /**
     * @param  array{access_token: string, expires_at: Carbon, scopes: list<string>, user_id: string, username: string|null}  $grant
     */
    private function confirmation(array $grant): string
    {
        $who = $grant['username'] === null ? 'Threads connected.' : "Threads connected as @{$grant['username']}.";

        if (in_array(ThreadsSearch::SCOPE, $grant['scopes'], true)) {
            return $who;
        }

        // Worth a sentence at the moment it is decided, because the difference
        // is invisible afterwards: listening keeps working and simply hears
        // less.
        return $who.' Keyword search was not granted, so listening will see only this account’s own posts.';
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
