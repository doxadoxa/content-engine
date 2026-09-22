<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Contracts\BillingProvider;
use App\Billing\PlanCatalog;
use App\Billing\PlanChanges;
use App\Billing\TrialEligibility;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        private readonly PlanChanges $changes,
    ) {}

    /** Start paying for this project. */
    public function checkout(Request $request): Response
    {
        $project = $this->projectOrFail();
        $user = $this->userOrFail($request);

        $validated = $request->validate([
            'plan' => ['required', 'string'],
            'plan_version' => ['nullable', 'integer'],
            'acknowledge_downgrade' => ['sometimes', 'accepted'],
        ]);

        if (isset($validated['plan_version']) && (int) $validated['plan_version'] !== $this->plans->currentVersion()) {
            throw ValidationException::withMessages(['plan' => 'The available offer changed. Reload its current price and allowance before continuing.']);
        }

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
        //
        // Against the *recorded payer*, not whoever pressed the button. A
        // project can have several owners and only one of them holds the
        // Cashier subscription, so looking it up under the requester found
        // nothing, reported "no subscription to change", and fell through to a
        // checkout — billing a second owner for a project already being paid
        // for. The requester's own customer record is only relevant when there
        // is nothing to change.
        $subscription = ProjectSubscription::query()->where('project_id', $project->getKey())->first();
        $payer = $subscription?->payer;

        if ($subscription?->stripe_id !== null) {
            // Refused before taking the lock too, so an unconfirmed downgrade
            // never waits on one. The decision itself is made again under the
            // lock: this copy of the subscription is already stale by the time
            // the lock is granted, and a concurrent change between the two
            // would otherwise pick the wrong branch — an unconfirmed downgrade
            // applied at once, or a schedule for a plan nobody is on any more.
            if ($this->changes->atRenewal($subscription, $plan) && ! $request->boolean('acknowledge_downgrade')) {
                throw ValidationException::withMessages(['plan' => 'Review the renewal date and scheduled articles, then confirm the lower allowance.']);
            }
            $lock = Cache::lock('billing-plan-change:'.$project->getKey(), 60);
            if (! $lock->get()) {
                return back()->with('billing', ['code' => 'plan_change_busy', 'message' => 'A plan change is already being confirmed. Refresh in a moment.', 'metric' => null]);
            }
            $renewal = false;
            try {
                $subscription->refresh();
                if ($subscription->plan === $plan->key && $subscription->plan_version === $plan->version && $subscription->pending_plan === null) {
                    return back();
                }
                $renewal = $this->changes->atRenewal($subscription, $plan);
                if ($renewal && ! $request->boolean('acknowledge_downgrade')) {
                    throw ValidationException::withMessages(['plan' => 'Review the renewal date and scheduled articles, then confirm the lower allowance.']);
                }
                $payer = $subscription->payer;
                if (! $payer instanceof User) {
                    throw new \RuntimeException('The recorded billing owner is unavailable.');
                }
                if ($renewal) {
                    if ($subscription->pending_plan !== $plan->key || $subscription->pending_plan_version !== $plan->version) {
                        $schedule = $this->provider->schedulePlanChange($payer, $project, $plan);
                        $subscription->fill(['pending_plan' => $plan->key, 'pending_plan_version' => $plan->version, 'pending_plan_at' => $subscription->period_ends_at, 'stripe_schedule_id' => $schedule])->save();
                    }
                } elseif (! $this->provider->changePlan($payer, $project, $plan)) {
                    throw new \RuntimeException('The provider could not confirm the change.');
                }
            } catch (ValidationException $e) {
                // The downgrade confirmation is an answer the person still has
                // to give, not a provider failure to report as one.
                throw $e;
            } catch (Throwable $e) {
                report($e);

                return back()->with('billing', [
                    'code' => 'plan_change_failed',
                    'message' => 'We could not confirm the plan change. Refresh billing to check its status before trying again.',
                    'metric' => null,
                ]);
            } finally {
                $lock->release();
            }
            Inertia::flash('toast', ['type' => 'success', 'message' => $renewal
                ? "{$plan->name} is scheduled for your next renewal. Your current allowance stays until then."
                : "{$plan->name} was requested. Billing updates after Stripe confirms; usage already counted stays counted."]);

            return back();
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

    public function cancelChange(Request $request): Response
    {
        $project = $this->projectOrFail();
        $this->userOrFail($request);
        $subscription = ProjectSubscription::query()->where('project_id', $project->getKey())->firstOrFail();
        $lock = Cache::lock('billing-plan-change:'.$project->getKey(), 60);
        if (! $lock->get()) {
            return back()->withErrors(['plan' => 'A plan change is already being confirmed.']);
        }
        try {
            $payer = $subscription->payer;
            if (! $payer instanceof User || ! $this->provider->cancelPlanChange($payer, $project)) {
                throw new \RuntimeException('The provider could not confirm cancellation.');
            }
            $subscription->refresh()->fill(['pending_plan' => null, 'pending_plan_version' => null, 'pending_plan_at' => null, 'stripe_schedule_id' => null, 'stripe_schedule_generation' => null])->save();
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Your current plan will continue at renewal.']);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['plan' => 'We could not confirm cancellation of the scheduled change. Refresh and try again.']);
        } finally {
            $lock->release();
        }

        return back();
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
