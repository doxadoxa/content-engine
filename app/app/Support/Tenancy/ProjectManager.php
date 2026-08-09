<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\OnboardingStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Which project a signed-in operator is working in, and how they change it.
 *
 * The choice lives in the session rather than the URL. The panel this replaced
 * put the project in the path, which reads well but means every link in the
 * application has to carry the tenant — and the first one that forgets sends
 * the operator to another project without saying so. One value, set here, read
 * by {@see CurrentProject}.
 */
final class ProjectManager
{
    public const SESSION_KEY = 'tenant.project_id';

    public function __construct(private readonly CurrentProject $current) {}

    /**
     * The project this request is about.
     *
     * Falls back rather than failing: a session pointing at a project the
     * operator has since been removed from is stale, not hostile, and turning
     * them away from the whole application for it would be a support ticket.
     */
    public function resolveCurrent(User $user): ?Project
    {
        $remembered = session()->get(self::SESSION_KEY);

        if (is_string($remembered)) {
            $project = $this->membershipOf($user, $remembered);

            if ($project !== null) {
                return $project;
            }

            session()->forget(self::SESSION_KEY);
        }

        return self::live($user)->orderBy('name')->first();
    }

    /**
     * The projects that count as real: a draft is a wizard somebody has not
     * finished, and scoping the panel to one shows a dashboard for a project
     * that has no brief, no plan and nothing running.
     *
     * @return BelongsToMany<Project, User>
     */
    public static function live(User $user): BelongsToMany
    {
        return $user->projects()
            ->where('onboarding_status', '!=', OnboardingStatus::Draft->value);
    }

    /**
     * Switch, if the operator is a member. Returns false when they are not,
     * so the caller answers 403 rather than silently staying put — a switcher
     * that appears to do nothing is worse than one that refuses.
     */
    public function switchTo(User $user, Project $project): bool
    {
        if ($this->membershipOf($user, $project->getKey()) === null) {
            return false;
        }

        session()->put(self::SESSION_KEY, $project->getKey());
        $this->current->set($project);

        return true;
    }

    private function membershipOf(User $user, string $projectId): ?Project
    {
        return self::live($user)->whereKey($projectId)->first();
    }
}
