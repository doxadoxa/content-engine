<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\Contracts\BillingProvider;
use App\Billing\StripeBillingProvider;
use App\Billing\Subscriptions;
use App\Enums\BillingStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every subscription, and where ours disagrees with Stripe's.
 *
 * The disagreement column is the reason this screen is not just the projects
 * list sorted differently. Entitlement is read from a local projection of what
 * Stripe told us, and a webhook lost to a deploy or a signature mismatch leaves
 * a project silently entitled or silently stopped — neither of which raises
 * anything, because both look exactly like normal operation. `billing:reconcile`
 * repairs it nightly; this is where somebody can see it before then.
 */
class AdminSubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', '');

        $subscriptions = ProjectSubscription::query()
            ->with(['project', 'payer'])
            ->when(
                BillingStatus::tryFrom($status) !== null,
                fn ($query) => $query->where('status', $status),
            )
            ->orderByRaw('period_ends_at asc nulls last')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('admin/subscriptions', [
            'status' => $status,
            'currency' => (string) config('billing.currency', 'eur'),
            'statuses' => array_map(
                static fn (BillingStatus $case): array => ['value' => $case->value, 'label' => $case->label()],
                BillingStatus::cases(),
            ),
            'subscriptions' => $subscriptions->through(fn (ProjectSubscription $s): array => [
                'id' => $s->getKey(),
                'project' => $s->project?->name,
                'project_id' => $s->project_id,
                'slug' => $s->project?->slug,
                'plan' => $s->plan()->name,
                'price_cents' => $s->plan()->priceCents,
                'status' => $s->status->value,
                'stripe_id' => $s->stripe_id,
                // Beside our own, so a row where the two disagree reads as one
                // line rather than as two screens compared by eye.
                'stripe_status' => $s->stripe_status,
                'disagrees' => $s->stripe_id !== null
                    && $s->stripe_status !== null
                    && StripeBillingProvider::statusFrom($s->stripe_status) !== $s->status,
                'payer' => $s->payer?->email,
                'period_ends_at' => $s->period_ends_at?->toIso8601String(),
                'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
                'grace_ends_at' => $s->grace_ends_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Ask Stripe what it actually thinks, now, for one subscription.
     *
     * The same comparison `billing:reconcile` makes nightly, on demand, because
     * the moment somebody is looking at a row that disagrees is the moment they
     * want it settled — not tomorrow at ten past four.
     */
    public function resync(
        Request $request,
        ProjectSubscription $subscription,
        BillingProvider $provider,
        Subscriptions $subscriptions,
    ): RedirectResponse {
        abort_if($subscription->stripe_id === null, 422, 'This subscription has no provider behind it.');

        $theirs = $provider->subscription($subscription->stripe_id);

        if ($theirs === null) {
            // Left alone: "Stripe did not answer" and "Stripe has never heard
            // of this" are the same shape from here, and cancelling a paying
            // customer over an API blip is far worse than a stale row.
            return back();
        }

        $before = [
            'status' => $subscription->status->value,
            'stripe_status' => $subscription->stripe_status,
            'period_started_at' => $subscription->period_started_at?->toIso8601String(),
        ];

        $project = $subscription->project;

        // The period as well as the status, through the same transition
        // `billing:reconcile` uses.
        //
        // This control exists for the case where a renewal webhook was missed,
        // and writing only `period_ends_at` left the local month back where it
        // was: counters still exhausted from a month the customer has already
        // paid past, spend still accumulating across an over-long window — while
        // the button reported success. The one thing somebody presses it to fix
        // was the one thing it did not do.
        $ourStart = $subscription->period_started_at;
        $theirStart = $theirs->periodStart;
        $movedOn = $theirStart !== null && ($ourStart === null || ! $ourStart->equalTo($theirStart));

        if ($movedOn && $project instanceof Project) {
            $subscriptions->renew(
                $project,
                $theirStart,
                $theirs->periodEnd ?? $theirStart->copy()->addMonth(),
            );

            $subscription->refresh();
        }

        $subscription->fill([
            'status' => $theirs->status,
            'stripe_status' => $theirs->rawStatus,
            'period_ends_at' => $theirs->periodEnd ?? $subscription->period_ends_at,
            'trial_ends_at' => $theirs->trialEnd,
            'canceled_at' => $theirs->canceledAt,
            'grace_ends_at' => $theirs->status === BillingStatus::PastDue
                ? ($subscription->grace_ends_at ?? Carbon::now()->addDays((int) config('billing.grace_days', 7)))
                : null,
        ])->save();

        // And running again if billing is what stopped it, for the reason the
        // webhook does the same: a subscription that reads healthy over a
        // paused project is a customer paying for silence.
        if ($project instanceof Project && $theirs->status->mayGenerate()) {
            $subscriptions->resume($project);
        }

        $user = $request->user();

        AdminAction::record(
            $user instanceof User ? $user : null,
            'subscription.resynced',
            $subscription->project,
            $before,
            ['status' => $theirs->status->value, 'stripe_status' => $theirs->rawStatus],
        );

        return back();
    }
}
