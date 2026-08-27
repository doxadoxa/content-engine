<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Billing\Entitlements;
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
        private readonly Entitlements $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Whatever the last request decided is not this request's answer.
        //
        // {@see Entitlements} memoises per project so that the several readers
        // inside one request cannot disagree with each other, and that memo is
        // scoped to a request by nothing but the container being rebuilt
        // between them — which php-fpm does and a long-lived worker does not.
        // The queued-job boundary is handled in AppServiceProvider; this is the
        // http one, and it is the same rule: a memo belongs to the request that
        // filled it.
        $this->entitlements->forget();

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
