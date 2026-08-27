<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Billing\Entitlements;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @var Collection<int, Project>|null
     */
    private ?Collection $memberships = null;

    public function __construct(
        private readonly CurrentProject $current,
        private readonly Entitlements $entitlements,
    ) {}

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Whether this deployment has a social presence at all
            // (config/social.php). Shared rather than passed per page because
            // the sidebar is rendered on every one of them, and the two entries
            // it decides — Today and Conversations — lead to routes that do not
            // exist when it is false.
            //
            // Hidden and not disabled. A greyed-out row is a promise that
            // something in the interface can turn it on, and nothing can:
            // `SOCIAL_PRESENCE_ENABLED` lives in the environment and needs a
            // Meta app behind it before it would mean anything.
            'social' => ['enabled' => (bool) config('social.enabled')],
            'auth' => [
                'user' => $request->user(),
                // Closures, because this method runs on the way *in* — before
                // route middleware — and the project is resolved by
                // EnsureCurrentProject. Inertia evaluates them at render time,
                // by which point there is one. Plain values here read null on
                // every page.
                'project' => fn () => $this->projectProps($request),
                'projects' => fn () => $this->projectOptions($request),
            ],
            // What the project may do, on every page.
            //
            // Shared rather than passed per screen because the thing it drives
            // is the frame — a countdown while a trial runs, a sentence when it
            // has stopped — and a banner that appeared only on the screens
            // somebody remembered to pass a prop to would be missing from
            // exactly the screens where the buttons are.
            //
            // A closure for the same reason the project is one: this runs
            // before route middleware, so the tenant is not resolved yet.
            'billing' => fn () => $this->billingProps(),
        ];
    }

    /**
     * What the current project is entitled to, flattened for the frame.
     *
     * Null when there is no project — a guest, or somebody mid-onboarding —
     * because there is nothing to say about a subscription that has no tenant
     * to belong to, and an empty banner is worse than none.
     *
     * @return array<string, mixed>|null
     */
    private function billingProps(): ?array
    {
        $project = $this->current->get();

        if (! $project instanceof Project) {
            return null;
        }

        return $this->entitlements->for($project)->toArray();
    }

    /**
     * The project being worked in.
     *
     * @return array<string, mixed>|null
     */
    private function projectProps(Request $request): ?array
    {
        $project = $this->current->get();
        $user = $request->user();

        if ($project === null || ! $user instanceof User) {
            return null;
        }

        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'status' => $project->status->value,
            'timezone' => $project->timezone,
            'default_locale' => $project->default_locale,
            'locales' => $project->locales,
            'role' => $this->roleIn($request, $project),
        ];
    }

    /**
     * Every project the viewer may switch to, for the sidebar's switcher.
     *
     * @return array<int, array<string, mixed>>
     */
    private function projectOptions(Request $request): array
    {
        return $this->memberships($request)
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status->value,
            ])
            ->all();
    }

    /**
     * Every project the viewer belongs to, loaded once per request.
     *
     * Both shared props need this list — the switcher for its options, the
     * current project for the viewer's role on the pivot — and Inertia
     * evaluates them as separate closures. Without memoising, every page ran
     * the same membership query twice.
     *
     * @return Collection<int, Project>
     */
    private function memberships(Request $request): Collection
    {
        $user = $request->user();

        if (! $user instanceof User) {
            /** @var Collection<int, Project> */
            return new Collection;
        }

        return $this->memberships ??= ProjectManager::live($user)->orderBy('name')->get();
    }

    private function roleIn(Request $request, Project $project): ?string
    {
        $membership = $this->memberships($request)->firstWhere('id', $project->getKey());

        $role = $membership?->getAttribute('pivot')?->getAttribute('role');

        return is_string($role) ? $role : null;
    }
}
