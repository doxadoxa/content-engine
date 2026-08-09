<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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

    public function __construct(private readonly CurrentProject $current) {}

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
        ];
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
