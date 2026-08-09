<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restrict project configuration changes to its owner membership. */
final class RequireProjectOwner
{
    public function __construct(private readonly CurrentProject $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $routeProject = $request->route('project');
        $project = $routeProject instanceof Project ? $routeProject : $this->current->get();

        abort_unless($user instanceof User && $project instanceof Project, 403);

        $membership = $user->projects()->whereKey($project->getKey())->first();

        abort_if($membership === null, 404);
        abort_unless($membership->pivot->getAttribute('role') === 'owner', 403);

        return $next($request);
    }
}
