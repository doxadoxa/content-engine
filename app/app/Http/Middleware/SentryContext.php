<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentProject;
use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

use function Sentry\configureScope;

/**
 * The two identifiers worth attaching to a fault, and nothing else.
 *
 * `send_default_pii` is off in config/sentry.php, which means Sentry attaches
 * no user at all — no id, no email, no address. That is the right default for
 * an application holding customers' unpublished work, but it also makes an
 * error report hard to act on: "something threw" without "for whom, in which
 * project" is a report you can only answer by guessing.
 *
 * So the identifiers come back one at a time and deliberately. A user id and a
 * project id are opaque outside this system, resolve to a person only through
 * our own database, and are exactly what turns a stack trace into a support
 * reply. An email address would add nothing to the diagnosis and would put a
 * customer's address in a third party's index — which is the trade
 * `send_default_pii` makes wholesale and this makes narrowly.
 *
 * Runs after {@see EnsureCurrentProject} in the web group, because that is what
 * resolves the project; before it, `CurrentProject` is empty and every event
 * would be tagged with no tenant.
 */
final class SentryContext
{
    public function __construct(private readonly CurrentProject $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $projectId = $this->current->id();

        configureScope(function (Scope $scope) use ($user, $projectId): void {
            if ($user !== null) {
                $scope->setUser(['id' => $user->getAuthIdentifier()]);
            }

            if ($projectId !== null) {
                $scope->setTag('project_id', $projectId);
            }
        });

        return $next($request);
    }
}
