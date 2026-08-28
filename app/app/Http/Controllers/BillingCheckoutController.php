<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Contracts\BillingProvider;
use App\Billing\PlanCatalog;
use App\Billing\TrialEligibility;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Out to Stripe, and back.
 *
 * Two verbs and no forms. Checkout and the Billing Portal are hosted, so this
 * class does nothing but decide *who* is going *where* — no card data reaches
 * this application, and plan changes, invoice history and cancellation stay on
 * the six screens Stripe already keeps correct.
 *
 * Owner-only, unlike the plan screen it is reached from. Reading which quotas
 * are left is an operator's business; committing the account holder's card is
 * not.
 */
class BillingCheckoutController extends Controller
{
    public function __construct(
        private readonly CurrentProject $current,
        private readonly BillingProvider $provider,
        private readonly PlanCatalog $plans,
        private readonly TrialEligibility $trials,
    ) {}

    /** Start paying for this project. */
    public function checkout(Request $request): Response
    {
        $project = $this->projectOrFail();
        $user = $this->userOrFail($request);

        $validated = $request->validate([
            'plan' => ['required', 'string'],
        ]);

        try {
            $plan = $this->plans->get((string) $validated['plan']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['plan' => 'There is no such plan.']);
        }

        // Enterprise is a conversation and a custom price. A checkout for it
        // would take somebody's money against limits nobody has agreed.
        if (! $plan->selfServe) {
            throw ValidationException::withMessages([
                'plan' => 'That plan is arranged with us rather than bought here.',
            ]);
        }

        // A customer who already pays is *changing* plan, not buying a second
        // one. A checkout opens a new recurring subscription, so sending an
        // existing subscriber through it would leave two of them at Stripe —
        // billed for both, while the one local row followed whichever webhook
        // arrived last.
        try {
            if ($this->provider->changePlan($user, $project, $plan)) {
                Inertia::flash('toast', [
                    'type' => 'success',
                    'message' => "This project is on {$plan->name} now. Stripe will settle the difference on your next invoice.",
                ]);

                return back();
            }
        } catch (Throwable $e) {
            report($e);

            return back()->with('billing', [
                'code' => 'plan_change_failed',
                'message' => 'We could not change the plan just now. Nothing has changed.',
                'metric' => null,
            ]);
        }

        try {
            $url = $this->provider->checkoutUrl(
                $user,
                $project,
                $plan,
                route('billing.index'),
                // A customer who cancelled and came back arrives here, because
                // `changePlan()` declines an invalid subscription. Carrying
                // free days unconditionally handed them the whole window again,
                // repeatably, on the same site.
                withTrial: $this->trials->mayHaveATrial($project),
            );
        } catch (Throwable $e) {
            // Reported and turned into a sentence, never a stack trace. The
            // person on the other end of this is trying to give us money, and
            // the failure is ours — a missing price id, a provider outage.
            report($e);

            return back()->with('billing', [
                'code' => 'checkout_failed',
                'message' => 'We could not open the checkout just now. Nothing has been charged.',
                'metric' => null,
            ]);
        }

        // `Inertia::location()`, not `redirect()->away()`.
        //
        // Both buttons that reach this are Inertia forms, so the request is an
        // XHR. A plain 302 to `checkout.stripe.com` is followed by the fetch
        // rather than by the browser, CORS blocks the cross-origin response,
        // and the button dead-ends on an Inertia error instead of reaching
        // Stripe. `Inertia::location()` answers 409 with `X-Inertia-Location`,
        // which is the one thing the client understands as "leave this
        // application".
        return Inertia::location($url);
    }

    /** Change the card, the plan, or their mind. */
    public function portal(Request $request): Response
    {
        $user = $this->userOrFail($request);
        $this->projectOrFail();

        try {
            $url = $this->provider->portalUrl($user, route('billing.index'));
        } catch (Throwable $e) {
            report($e);

            return back()->with('billing', [
                'code' => 'portal_failed',
                'message' => 'We could not open the billing portal just now.',
                'metric' => null,
            ]);
        }

        return Inertia::location($url);
    }

    private function projectOrFail(): Project
    {
        $project = $this->current->get();

        abort_unless($project instanceof Project, 404);

        return $project;
    }

    private function userOrFail(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
