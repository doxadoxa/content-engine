<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectManager;
use App\Support\Tenancy\ProjectScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts a project on every authenticated request.
 *
 * On the whole authenticated group rather than per-route. {@see ProjectScope}
 * fails closed, so a controller that reads a tenant-owned model on a route this
 * did not run on returns an empty list — which looks like "no content yet"
 * rather than like a bug, and is the kind of thing that survives to production.
 *
 * Runs before Inertia's middleware, because that one collects its shared props
 * on the way *in*: a project resolved after it would not be on the page, and
 * the switcher would render empty on the first load of every session.
 */
final class EnsureCurrentProject
{
    public function __construct(
        private readonly ProjectManager $projects,
        private readonly CurrentProject $current,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $project = $this->projects->resolveCurrent($user);

            if ($project !== null) {
                $this->current->set($project);
            }
        }

        return $next($request);
    }
}
