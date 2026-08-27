<?php

declare(strict_types=1);

use App\Enums\BillingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every project that existed before billing did.
 *
 * Entitlement fails closed: a project with no subscription row may not spend,
 * which is the right default for a project that never had one and the wrong
 * thing to do to a project that has been running for months. Without this, the
 * deploy that adds billing is a deploy that switches the engine off for
 * everybody currently using it — silently, because a tick that skips a project
 * for a billing reason looks exactly like a tick with nothing to do.
 *
 * They are grandfathered onto Medium, which is the plan whose cadence ceiling
 * (7 a week) matches what the engine already does by default. A migration
 * should preserve existing behaviour rather than make a pricing decision, so
 * this changes nothing about how any project runs today; deciding what somebody
 * should actually be on is a job for `billing:assign`, done deliberately.
 *
 * `active` rather than `trialing` for the same reason. Starting a three-day
 * clock on somebody who has been a user for months would end their service on
 * Thursday.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        $projects = DB::table('projects')
            ->whereNotIn('id', DB::table('project_subscriptions')->select('project_id'))
            ->pluck('id');

        if ($projects->isEmpty()) {
            return;
        }

        DB::table('project_subscriptions')->insert($projects->map(fn (string $id): array => [
            'id' => (string) Str::ulid(),
            'project_id' => $id,
            'billing_user_id' => null,
            'plan' => 'medium',
            'plan_version' => 1,
            'status' => BillingStatus::Active->value,
            'limit_overrides' => '{}',
            'period_started_at' => $now,
            // A year, not a month. Nothing renews these — there is no provider
            // behind them — and a month would quietly expire the grandfathered
            // projects thirty days after the deploy, which is the same outage
            // this migration exists to prevent, merely postponed.
            'period_ends_at' => $now->copy()->addYear(),
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'canceled_at' => null,
            'stripe_id' => null,
            'stripe_status' => null,
            'stripe_price' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    public function down(): void
    {
        // Only the rows this made: a subscription somebody has since bought
        // must survive a rollback of the grandfathering.
        DB::table('project_subscriptions')
            ->whereNull('stripe_id')
            ->where('plan', 'medium')
            ->whereNull('billing_user_id')
            ->delete();
    }
};
