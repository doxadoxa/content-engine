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
            // A month, like every other period, and `billing:sweep` rolls it
            // over. A longer window was the first instinct — nothing renews
            // these, so why expire them — and it was wrong twice over: a period
            // is also the window the *cost ceiling* sums spend across, so a
            // year-long one accumulates a year of spend against a fuse sized
            // for a month and trips it about ninety days in, with a refusal
            // that deliberately says nothing diagnostic. That is the same
            // outage this migration exists to prevent, merely postponed and
            // made harder to read.
            'period_ends_at' => $now->copy()->addMonth(),
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

    /**
     * Deliberately nothing.
     *
     * There is no predicate that distinguishes a row this migration wrote from
     * one `billing:assign <project> medium` wrote — no provider id, no payer,
     * the same plan — which is exactly how somebody's comped subscription would
     * be deleted by a rollback. And the cost of leaving a row is small and
     * visible: a project stays entitled, which is the state it was in before
     * this migration ran. The cost of deleting the wrong one is a customer
     * whose engine stops for a reason nobody can reconstruct.
     *
     * The table itself is dropped by `create_billing_tables`, so rolling the
     * batch back does clean up; this only refuses to guess halfway.
     */
    public function down(): void
    {
        //
    }
};
