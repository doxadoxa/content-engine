<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Entitlement;
use App\Billing\Subscriptions;
use App\Enums\BillingStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
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

    protected $description = 'End trials and dunning graces that have run out';

    public function handle(Subscriptions $subscriptions): int
    {
        $dry = (bool) $this->option('dry');
        $now = Carbon::now();

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
            $this->components->info('Nothing has run out.');

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
}
