<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Integrations\Google\GooglePanel;
use App\Integrations\Threads\ThreadsPanel;
use App\Models\Project;
use App\Models\User;
use App\Support\Duty\DutyHours;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectManager $projects,
        private readonly GooglePanel $google,
        private readonly ThreadsPanel $threads,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('projects/index', [
            'projects' => $this->memberProjects($request)
                ->map(fn (Project $project): array => $this->toProps($project))
                ->all(),
        ]);
    }

    public function edit(Request $request, Project $project): Response
    {
        $this->authorizeMembership($request, $project);

        return Inertia::render('projects/edit', [
            'project' => $this->toProps($project),
            'timezones' => $this->timezones(),
            // Deferred: listing properties is two calls to Google, and the
            // settings form above must not wait on somebody else's API to
            // render. An operator who came here to rename the project should
            // not notice this panel exists.
            'google' => Inertia::defer(fn (): array => $this->google->panelFor($project)),
            // Not deferred, unlike Google's: this one reads a row and makes no
            // outbound call, so there is nothing to wait for and a placeholder
            // would flash for no reason.
            //
            // Absent, not `unavailable`, when the social presence is switched
            // off (config/social.php). The panel's `unavailable` state means
            // "this installation wants Threads and has not been given an app
            // yet" and it says so with the two variable names to fill in; a
            // deployment that has switched the feature off is not waiting for
            // credentials, and telling it how to add some would be advice
            // nobody asked for. The card is dropped on the client side, since
            // an empty card is worse than either.
            ...(config('social.enabled')
                ? ['threads' => $this->threads->panelFor($project)]
                : []),
        ]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $this->authorizeMembership($request, $project);

        $data = $request->safe()->except('slug');

        // Through the value object rather than straight from the form, so the
        // column only ever holds the canonical shape: days in week order,
        // hours zero-padded, touching windows merged, unusable ranges dropped.
        // A half-typed entry that reached the database raw would read back as
        // a different set of duty hours on every planning run.
        if (array_key_exists('duty_hours', $data)) {
            $submitted = $data['duty_hours'];

            $data['duty_hours'] = DutyHours::fromArray(is_array($submitted) ? $submitted : null)->toArray();
        }

        $project->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$project->name} updated."]);

        return to_route('projects.index');
    }

    /**
     * Change which project the rest of the application is about.
     *
     * A POST, not a GET: it changes what every subsequent page contains, and a
     * browser is free to prefetch a GET.
     */
    public function switch(Request $request, Project $project): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($this->projects->switchTo($user, $project), 403);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Now working in {$project->name}."]);

        return back();
    }

    /**
     * Route model binding resolves any project by id, including one the viewer
     * is not in. Membership is what makes it theirs.
     *
     * 404 rather than 403 on purpose: an operator who is not in a project
     * should not learn that its id is real.
     */
    private function authorizeMembership(Request $request, Project $project): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->projects()->whereKey($project->getKey())->exists(), 404);
    }

    /**
     * @return Collection<int, Project>
     */
    private function memberProjects(Request $request): Collection
    {
        /** @var User $user */
        $user = $request->user();

        // Drafts are unfinished wizards, not projects. They are reachable
        // from /onboarding and nowhere else.
        return ProjectManager::live($user)->orderBy('name')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function toProps(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'status' => $project->status->value,
            'timezone' => $project->timezone,
            'duty_hours' => $project->dutyHours()->toArray(),
            'feed_urls' => $project->feedUrls(),
            'default_locale' => $project->default_locale,
            'locales' => $project->locales,
            'market' => $project->market,
            'weekly_target' => $project->weekly_target,
            'research_seeds' => $project->research_seeds,
            'minimum_volume' => $project->minimum_volume,
            'created_at' => $project->created_at?->toIso8601String(),
            'role' => $this->role($project),
        ];
    }

    private function role(Project $project): ?string
    {
        $pivot = $project->getAttribute('pivot');
        $role = $pivot instanceof Pivot ? $pivot->getAttribute('role') : null;

        return is_string($role) ? $role : null;
    }

    /**
     * @return list<string>
     */
    private function timezones(): array
    {
        return timezone_identifiers_list();
    }
}
