<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * In front of every route where pressing the button spends our money.
 *
 * The routes it guards already carry throttles, and this sits beside them
 * answering a different question: the throttle bounds how *fast* somebody can
 * spend, and this bounds whether they may at all.
 *
 * Reading is never behind this. An expired project's articles, briefs, metrics
 * and audits stay reachable for ever — the plan bounds what gets *made*, and a
 * customer who stops paying does not stop owning what they already paid for.
 *
 * Takes the metric it is protecting as a parameter, so the refusal can name
 * which quota ran out: `project.entitled:articles`. Without one it asks only
 * whether the project may spend at all, which is right for the routes that
 * revise something already made.
 */
final class RequireEntitlement
{
    public function __construct(
        private readonly CurrentProject $current,
        private readonly Entitlements $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, ?string $metric = null): Response
    {
        $project = $this->current->get();

        // No project is not a billing answer. `EnsureCurrentProject` runs
        // before this and leaves it null only for somebody with no membership
        // at all, which is an authorisation problem and belongs to whatever
        // handles that — not a 402 telling them to buy something.
        if (! $project instanceof Project) {
            return $next($request);
        }

        $entitlement = $this->entitlements->for($project);
        $refusal = $entitlement->refusal($metric === null ? null : Metric::from($metric));

        if ($refusal === null) {
            return $next($request);
        }

        // Back where they were, with the sentence, rather than to a wall. Every
        // guarded route is a button on a screen the operator can still read,
        // and throwing them onto a paywall page would take away the work they
        // were looking at to tell them about the work they cannot start.
        //
        // `Inertia::flash('toast')`, because that is the only flash this
        // application actually renders — `resources/js/hooks/use-flash-toast.ts`
        // reads exactly that key and nothing else. A plain `with('billing', …)`
        // put the refusal in the session where the shared `billing` prop, which
        // is computed independently, overrode nothing and read nothing: the
        // operator pressed the button, the page came back unchanged, and the
        // reason went to a key nobody looks at.
        Inertia::flash('toast', [
            'type' => 'error',
            'message' => $refusal->message,
        ]);

        return back();
    }
}
