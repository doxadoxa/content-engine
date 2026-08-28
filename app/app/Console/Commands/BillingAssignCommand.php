<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\PlanCatalog;
use App\Billing\Subscriptions;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `php artisan billing:assign <project> <plan>` — put a project on a plan by
 * hand.
 *
 * The whole of phase 2's billing interface, on purpose. Entitlement is the part
 * with the risk in it: if the gates are wrong, adding a payment provider only
 * makes them wrong with money attached. So the gating story is built and
 * exercised first, with plans assigned from a terminal, and Stripe arrives
 * afterwards to call exactly the transitions this command calls.
 *
 * It stays after that, for the two things a payment provider is bad at:
 * comping a plan for somebody we owe a favour, and provisioning Enterprise,
 * which is a conversation rather than a checkout.
 */
class BillingAssignCommand extends Command
{
    protected $signature = 'billing:assign
        {project : Project slug or id}
        {plan : A plan key, or `trial`}
        {--payer= : Email of the account that pays}
        {--resume : Set the project back to active if it was paused}';

    protected $description = 'Put a project on a plan without going through a checkout';

    public function handle(Subscriptions $subscriptions, PlanCatalog $plans): int
    {
        $project = $this->resolveProject((string) $this->argument('project'));

        if ($project === null) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        $payer = $this->resolvePayer();

        if ($payer === false) {
            $this->components->error('No account with that email.');

            return self::FAILURE;
        }

        $plan = (string) $this->argument('plan');

        try {
            $subscription = $plan === 'trial'
                ? $subscriptions->startTrial($project, $payer)
                : $subscriptions->assign($project, $plan, $payer);
        } catch (InvalidArgumentException $e) {
            // The catalogue refuses an unknown plan rather than guessing, and
            // this is where that refusal becomes a sentence: defaulting up
            // gives the product away and defaulting down locks a paying
            // customer out, and both would happen quietly.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // A trial is idempotent — a project that already has a subscription
        // keeps it — so say which of the two happened rather than reporting a
        // change that did not occur.
        if ($plan === 'trial' && $subscription->plan !== 'trial') {
            $this->components->warn(
                "{$project->slug} already has a {$subscription->plan} subscription; left alone."
            );

            return self::SUCCESS;
        }

        if ($this->option('resume') && $project->status !== ProjectStatus::Active) {
            $project->forceFill(['status' => ProjectStatus::Active])->save();
            $this->line("  {$project->slug}: resumed");
        }

        $resolved = $subscription->plan();

        $this->components->info("{$project->name} is on {$resolved->name}.");
        $this->components->twoColumnDetail('Status', $subscription->status->label());
        $this->components->twoColumnDetail(
            'Period ends',
            $subscription->period_ends_at?->toDayDateTimeString() ?? '—',
        );
        $this->components->twoColumnDetail(
            'Cadence ceiling',
            ($resolved->weeklyTarget() ?? 0) === 0 ? 'unlimited' : $resolved->weeklyTarget().' a week',
        );

        foreach ($resolved->limits() as $key => $limit) {
            if ($key === 'cost_micros' || $key === 'weekly_target') {
                continue;
            }

            $this->components->twoColumnDetail(
                '  '.str_replace('_', ' ', (string) $key),
                $limit === null ? 'unlimited' : (string) $limit,
            );
        }

        if ($project->status !== ProjectStatus::Active) {
            // Worth saying plainly. A plan on a paused project buys nothing
            // until somebody resumes it, and discovering that an hour later
            // through an engine that never ticked is a bad way to find out.
            $this->components->warn(
                "{$project->slug} is {$project->status->value}; the engine will not run until it is active (--resume)."
            );
        }

        return self::SUCCESS;
    }

    private function resolveProject(string $handle): ?Project
    {
        return Project::query()->where('slug', $handle)->first()
            ?? Project::query()->whereKey($handle)->first();
    }

    /** @return User|null|false false when an email was given and matched nothing. */
    private function resolvePayer(): User|null|false
    {
        $email = $this->option('payer');

        if (! is_string($email) || $email === '') {
            return null;
        }

        return User::query()->where('email', $email)->first() ?? false;
    }
}
