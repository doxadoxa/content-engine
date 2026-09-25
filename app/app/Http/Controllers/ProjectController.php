<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Subscriptions;
use App\Enums\BillingStatus;
use App\Enums\OnboardingStatus;
use App\Enums\ProjectStatus;
use App\Http\Requests\ProjectRequest;
use App\Integrations\Google\GooglePanel;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectManager $projects,
        private readonly GooglePanel $google,
        private readonly BillingProvider $provider,
        private readonly Subscriptions $subscriptions,
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
        ]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $this->authorizeMembership($request, $project);

        $data = $request->safe()->except('slug');

        DB::transaction(function () use ($request, $project, $data): void {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('autopublish', $data)) {
                /** @var User $actor */
                $actor = $request->user();
                abort_unless($actor->projects()->whereKey($locked->id)->wherePivot('role', 'owner')->exists(), 403);
                $data['onboarding'] = [...$locked->onboarding,
                    'article_automation_started_at' => $locked->onboarding['article_automation_started_at'] ?? now()->toIso8601String()];
            }
            $locked->update($data);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$project->name} updated."]);

        return to_route('projects.index');
    }

    /**
     * Be done with a project: stop its work, stop its billing, and take it out
     * of every list its members see.
     *
     * Archived rather than deleted. Several tables refuse to let a project row
     * go, and the once-per-site free sample is judged by the rows old projects
     * leave behind — deleting would hand anybody a fresh sample for the price
     * of one click. Nothing in the application brings it back; support can.
     *
     * The owner types the name, because this is the one control on the screen
     * that cannot be undone from it.
     */
    public function archive(Request $request, Project $project): RedirectResponse
    {
        // A draft is an unfinished wizard, not a project; it has nothing to
        // stop and no list to leave.
        abort_if($project->onboarding_status === OnboardingStatus::Draft, 404);

        $request->validate(
            ['confirmation' => ['required', 'string', Rule::in([$project->name])]],
            ['confirmation.in' => 'Type the project name exactly as shown to confirm.'],
        );

        /** @var User $user */
        $user = $request->user();

        // The lock a plan change takes, so a change being confirmed at Stripe
        // cannot land on a subscription this is ending.
        $lock = Cache::lock('billing-plan-change:'.$project->getKey(), 60);

        if (! $lock->get()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'A billing change for this project is in progress. Try again in a moment.']);

            return back();
        }

        try {
            // Stripe first, outside the row lock, and the archive only if it
            // worked. The other order can leave a project hidden from its owner
            // and still being charged for, with no screen left to fix it from;
            // this one at worst leaves a live project whose subscription has
            // ended, which the billing page already knows how to show.
            $subscription = ProjectSubscription::query()->where('project_id', $project->getKey())->first();
            $canceledAtStripe = null;

            if ($subscription?->stripe_id !== null && $subscription->status !== BillingStatus::Canceled) {
                try {
                    // The owner archiving, when no payer was recorded. The
                    // provider still checks the subscription is theirs.
                    $payer = $subscription->payer ?? $user;

                    if (! $this->provider->cancelSubscription($payer, $project)) {
                        throw new RuntimeException('The provider could not confirm the cancellation.');
                    }

                    $canceledAtStripe = $subscription->stripe_id;
                } catch (Throwable $e) {
                    report($e);

                    Inertia::flash('toast', ['type' => 'error', 'message' => 'We could not cancel the subscription for this project, so it has not been archived. Please contact support.']);

                    return back();
                }
            }

            $archived = DB::transaction(function () use ($project, $canceledAtStripe): bool {
                $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->archived_at !== null) {
                    return true;
                }

                // Read again under the lock the webhook also takes first. A
                // checkout that completed since the read above has a live
                // subscription at Stripe nothing here has ended, and archiving
                // over it would hide a project that is being charged for. The
                // next attempt sees it up front and cancels it the usual way.
                $current = ProjectSubscription::query()->where('project_id', $locked->getKey())->first();

                if ($current?->stripe_id !== null && $current->status !== BillingStatus::Canceled && $current->stripe_id !== $canceledAtStripe) {
                    return false;
                }

                // Paused is what every scheduler and pipeline already skips, so
                // nothing downstream needs to learn a new word for "stopped".
                $locked->forceFill([
                    'archived_at' => now(),
                    'status' => ProjectStatus::Paused,
                ])->save();

                // Locally too, for a preview or a comp with nothing at Stripe,
                // and so the row does not wait on a webhook to stop reading as
                // billed. An ended subscription keeps the date it ended on.
                if (ProjectSubscription::query()->where('project_id', $locked->getKey())->where('status', '!=', BillingStatus::Canceled->value)->exists()) {
                    $this->subscriptions->cancel($locked);
                }

                return true;
            });
        } finally {
            $lock->release();
        }

        if (! $archived) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'A billing change for this project just came in. Try again in a moment.']);

            return back();
        }

        // Resolved again on the next request, which will now pick another
        // project — or none, and the wizard.
        if ($request->session()->get(ProjectManager::SESSION_KEY) === $project->getKey()) {
            $request->session()->forget(ProjectManager::SESSION_KEY);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$project->name} archived."]);

        return ProjectManager::live($user)->exists()
            ? to_route('projects.index')
            : to_route('onboarding.show');
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
            'autopublish' => $project->autopublish,
            'article_scheduling_enabled' => is_string($project->onboarding['article_automation_started_at'] ?? null),
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
