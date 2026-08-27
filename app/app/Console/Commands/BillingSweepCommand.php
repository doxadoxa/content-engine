<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Entitlement;
use App\Billing\Subscriptions;
use App\Enums\BillingStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Support\Metering\ProjectSpend;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * `php artisan billing:sweep` — the part of billing that happens because time
 * passed rather than because somebody did something.
 *
 * Two transitions have no event behind them. A trial ends because three days
 * went by, and a dunning grace ends because a week did; nobody presses
 * anything, no webhook arrives, and without this the row would sit at
 * `trialing` for ever while {@see Entitlement} refused it live on
 * every request.
 *
 * That live refusal is why this is a sweep and not a lock. Entitlement is
 * decided by reading dates, so a project whose trial ran out is refused the
 * instant it expires whether or not this command has run — the sweep only
 * makes the *record* say what the reading already says, and pauses the project
 * so `engine:tick` stops considering it at all. Getting this backwards, and
 * making expiry mean "the sweep flipped a column", would leave a stopped
 * scheduler handing out free service.
 *
 * Idempotent and bounded. It runs hourly beside the rest and must be safe to
 * run twice in the same minute.
 */
class BillingSweepCommand extends Command
{
    protected $signature = 'billing:sweep {--dry : Report what would change and change nothing}';

    protected $description = 'Roll periods over, and end trials and graces that have run out';

    public function handle(Subscriptions $subscriptions): int
    {
        $dry = (bool) $this->option('dry');
        $now = Carbon::now();

        // Rolled over before anything is ended, and that order matters: a
        // period that has run out is not an expiry, and a project whose month
        // simply turned must not be looked at by the expiry pass below.
        $rolled = $this->rollPeriods($subscriptions, $now, $dry);

        $expired = ProjectSubscription::query()
            ->with('project')
            ->where(fn ($query) => $query
                ->where(fn ($trial) => $trial
                    ->where('status', BillingStatus::Trialing)
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '<=', $now))
                ->orWhere(fn ($grace) => $grace
                    ->where('status', BillingStatus::PastDue)
                    ->whereNotNull('grace_ends_at')
                    ->where('grace_ends_at', '<=', $now)))
            ->get();

        if ($expired->isEmpty()) {
            $this->components->info($rolled === 0
                ? 'Nothing has run out.'
                : ($dry ? "{$rolled} period(s) would roll over. Nothing has run out." : "{$rolled} period(s) rolled over. Nothing has run out."));

            return self::SUCCESS;
        }

        foreach ($expired as $subscription) {
            $project = $subscription->project;

            if (! $project instanceof Project) {
                continue;
            }

            $why = $subscription->status === BillingStatus::Trialing ? 'trial ended' : 'dunning ran out';

            if ($dry) {
                $this->line("  would cancel {$project->slug}: {$why}");

                continue;
            }

            $subscriptions->cancel($project, $now);

            // Paused rather than anything new. The status already means
            // "scheduled pipelines skip this project; existing data stays
            // readable", which is exactly where a lapsed customer belongs — and
            // reusing it means every screen that already understands a paused
            // project understands this one too.
            if ($project->status !== ProjectStatus::Paused) {
                $project->forceFill(['status' => ProjectStatus::Paused])->save();
            }

            $this->line("  {$project->slug}: {$why}, paused");
        }

        $this->components->info(($dry ? 'Would end ' : 'Ended ').$expired->count().' subscription(s).');

        return self::SUCCESS;
    }

    /**
     * Move a month on when it is over.
     *
     * Nothing else did this, and the omission was silent in the worst way. A
     * period is not only what the unit counters are keyed to — it is also the
     * window {@see ProjectSpend} sums for the cost
     * ceiling. A subscription whose period never advances therefore accumulates
     * spend for ever against a fuse sized for one month, and crosses it a few
     * months in with a refusal that deliberately says nothing useful. The
     * grandfathered projects, on a year-long period, would all have stopped
     * roughly ninety days after the deploy that was meant to keep them running.
     *
     * Only subscriptions with nothing at the provider behind them. Where Stripe
     * is the source of truth, the new window arrives on
     * `customer.subscription.updated` with real dates on it, and
     * `billing:reconcile` is the net under that. Rolling those forward here
     * would invent a period Stripe disagrees with.
     */
    private function rollPeriods(Subscriptions $subscriptions, Carbon $now, bool $dry): int
    {
        $due = ProjectSubscription::query()
            ->with('project')
            ->whereNull('stripe_id')
            ->where('status', BillingStatus::Active)
            ->whereNotNull('period_ends_at')
            ->where('period_ends_at', '<=', $now)
            ->get();

        foreach ($due as $subscription) {
            $project = $subscription->project;

            if (! $project instanceof Project) {
                continue;
            }

            // From the end of the last period rather than from now, so a sweep
            // that did not run for a day does not shorten the month it is
            // catching up on. Wound forward if it has been longer than that,
            // because handing somebody six months of quota at once for having
            // been away is the other way to get this wrong.
            $from = $subscription->period_ends_at ?? $now;

            while ($from->lessThanOrEqualTo($now->copy()->subMonth())) {
                $from = $from->copy()->addMonth();
            }

            if ($dry) {
                $this->line("  would roll {$project->slug} on to ".$from->toDateString());

                continue;
            }

            $subscriptions->renew($project, $from, $from->copy()->addMonth());

            $this->line("  {$project->slug}: new period from ".$from->toDateString());
        }

        return $due->count();
    }
}
