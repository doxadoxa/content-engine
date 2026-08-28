<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who runs the service, as opposed to who runs a project.
 *
 * Not a project role and never derivable from one. An owner is the most
 * privileged person *inside* one tenant; this is somebody who can see across
 * all of them, which is a different kind of thing entirely — and the reason
 * every read behind it has to opt into `acrossProjects()` rather than getting
 * that for free.
 *
 * 404 rather than 403 for somebody signed in without the flag. A 403 confirms
 * that `/admin` is a real address on this deployment, and there is nothing here
 * an ordinary customer gains by knowing that.
 */
final class RequireAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_admin, 404);

        return $next($request);
    }
}
