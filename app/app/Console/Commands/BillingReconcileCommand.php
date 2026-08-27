<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Contracts\BillingProvider;
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

    public function handle(BillingProvider $provider): int
    {
        $dry = (bool) $this->option('dry');

        $subscriptions = ProjectSubscription::query()
            ->with('project')
            ->whereNotNull('stripe_id')
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->components->info('No subscriptions have a provider behind them yet.');

            return self::SUCCESS;
        }

        $drifted = 0;

        foreach ($subscriptions as $subscription) {
            $stripeId = (string) $subscription->stripe_id;
            $theirs = $provider->subscription($stripeId);
            $slug = $subscription->project->slug;

            if ($theirs === null) {
                // Left alone, deliberately. "Stripe did not answer" and "Stripe
                // has never heard of this" are the same shape from here, and
                // cancelling a paying customer because of a five-second API
                // outage is far worse than leaving a stale row for an hour.
                $this->components->warn("  {$slug}: Stripe returned nothing for {$stripeId} — left alone");

                continue;
            }

            if ($theirs->status === $subscription->status
                && $theirs->id === $subscription->stripe_id) {
                continue;
            }

            $drifted++;

            $was = $subscription->status->value;
            $now = $theirs->status->value;

            if ($dry) {
                $this->line("  would correct {$slug}: {$was} → {$now}");

                continue;
            }

            $subscription->fill([
                'status' => $theirs->status,
                'stripe_status' => $theirs->status->value,
                'period_ends_at' => $theirs->periodEnd ?? $subscription->period_ends_at,
                'trial_ends_at' => $theirs->trialEnd,
                'canceled_at' => $theirs->canceledAt,
            ])->save();

            // Loudly. A projection that drifted is evidence a webhook was lost,
            // and the repair is the only place that fact is ever visible.
            Log::warning('Local entitlement disagreed with Stripe and was corrected', [
                'project' => $subscription->project_id,
                'stripe_id' => $stripeId,
                'was' => $was,
                'now' => $now,
            ]);

            $this->line("  {$slug}: {$was} → {$now}");
        }

        $this->components->info($drifted === 0
            ? 'Everything agrees with Stripe.'
            : ($dry ? "{$drifted} would be corrected." : "{$drifted} corrected."));

        return self::SUCCESS;
    }
}
