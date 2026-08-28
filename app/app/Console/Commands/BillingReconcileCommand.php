<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Subscriptions;
use App\Enums\BillingStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan billing:reconcile` — because a webhook will be missed.
 *
 * Not a maintenance nicety. Entitlement is read from a local projection, and
 * that projection is only ever correct because Stripe told us something; a
 * webhook lost to a deploy, a timeout or a signature mismatch leaves a project
 * silently entitled or silently stopped, and neither shows up as an error
 * anywhere. Both are worse than an outage, because both look like normal
 * operation.
 *
 * So this runs on the scheduler beside the rest, and not only as a repair
 * somebody remembers to invoke.
 *
 * Only rows that have a Stripe subscription behind them are compared. A trial,
 * a comped plan and an Enterprise deal assigned from the terminal have nothing
 * at the provider to disagree with.
 */
class BillingReconcileCommand extends Command
{
    protected $signature = 'billing:reconcile
        {--dry : Report what disagrees and change nothing}';

    protected $description = 'Compare local entitlement against what Stripe actually says';

    public function handle(BillingProvider $provider, Subscriptions $subscriptions): int
    {
        $dry = (bool) $this->option('dry');

        $rows = ProjectSubscription::query()
            ->with('project')
            ->whereNotNull('stripe_id')
            ->get();

        if ($rows->isEmpty()) {
            $this->components->info('No subscriptions have a provider behind them yet.');

            return self::SUCCESS;
        }

        $drifted = 0;

        foreach ($rows as $subscription) {
            $stripeId = (string) $subscription->stripe_id;
            $theirs = $provider->subscription($stripeId);
            $project = $subscription->project;
            $slug = $project->slug;

            if ($theirs === null) {
                // Left alone, deliberately. "Stripe did not answer" and "Stripe
                // has never heard of this" are the same shape from here, and
                // cancelling a paying customer because of a five-second API
                // outage is far worse than leaving a stale row for an hour.
                $this->components->warn("  {$slug}: Stripe returned nothing for {$stripeId} — left alone");

                continue;
            }

            // The period as well as the status, and this is half the point of
            // the command. When a renewal webhook is lost, nothing else moves
            // a provider-backed period — `billing:sweep` deliberately skips
            // these rows — so the customer's counters stay exhausted from last
            // month and `spentMicros` keeps summing an ever-lengthening window
            // against a one-month fuse until it trips with a refusal written to
            // say nothing diagnostic.
            $theirStart = $theirs->periodStart;
            $ourStart = $subscription->period_started_at;
            $movedOn = $theirStart !== null && ($ourStart === null || ! $ourStart->equalTo($theirStart));

            if ($theirs->status === $subscription->status && ! $movedOn) {
                continue;
            }

            $drifted++;

            $was = $subscription->status->value;
            $now = $theirs->status->value;
            $what = $movedOn && $was === $now ? 'period' : "{$was} → {$now}";

            if ($dry) {
                $this->line("  would correct {$slug}: {$what}");

                continue;
            }

            // No null check on `$theirStart`: `$movedOn` is only true when it
            // is set, and analysis can see that.
            if ($movedOn) {
                // Through `renew()`, so the counters reset the way they would
                // have if the webhook had arrived.
                $subscriptions->renew(
                    $project,
                    $theirStart,
                    $theirs->periodEnd ?? $theirStart->copy()->addMonth(),
                );

                $subscription->refresh();
            }

            $subscription->fill([
                // Stripe's own word, unmapped. Writing our reduced vocabulary
                // into a column documented as holding the provider's would
                // destroy the only record of what we were actually told.
                'stripe_status' => $theirs->rawStatus,
                'trial_ends_at' => $theirs->trialEnd,
            ])->save();

            // And the status through the transitions rather than as a column,
            // for the reason the webhook does it that way: `past_due` carries a
            // grace deadline with it, and a row that reaches that state without
            // one has nothing to expire — it publishes for ever on a dead card.
            match ($theirs->status) {
                BillingStatus::PastDue => $subscriptions->markPastDue($project),
                BillingStatus::Canceled => $subscriptions->cancel($project, $theirs->canceledAt),
                // Healthy again — and this arm is the single most important
                // repair the command makes. When the *payment finally
                // succeeded* webhook is the one that got lost, the row sits at
                // past due: generation refused, and the grace quietly
                // cancelling a customer who has paid. Without this the command
                // counted that as drift, logged that it had corrected it,
                // printed the row, and changed nothing.
                default => $this->restore($subscriptions, $project, $subscription, $theirs->status),
            };

            // Loudly. A projection that drifted is evidence a webhook was lost,
            // and the repair is the only place that fact is ever visible.
            Log::warning('Local entitlement disagreed with Stripe and was corrected', [
                'project' => $subscription->project_id,
                'stripe_id' => $stripeId,
                'was' => $was,
                'now' => $now,
                'period_moved' => $movedOn,
            ]);

            $this->line("  {$slug}: {$what}");
        }

        $this->components->info($drifted === 0
            ? 'Everything agrees with Stripe.'
            : ($dry ? "{$drifted} would be corrected." : "{$drifted} corrected."));

        return self::SUCCESS;
    }

    /**
     * Healthy again — and running again.
     *
     * The same omission the webhook had: restoring the subscription fields
     * leaves a project the sweep paused still paused, so a customer whose lost
     * webhook this command exists to repair goes on paying for silence.
     */
    private function restore(
        Subscriptions $subscriptions,
        Project $project,
        ProjectSubscription $subscription,
        BillingStatus $status,
    ): void {
        $subscription->fill([
            'status' => $status,
            'grace_ends_at' => null,
        ])->save();

        $subscriptions->resume($project);
    }
}
