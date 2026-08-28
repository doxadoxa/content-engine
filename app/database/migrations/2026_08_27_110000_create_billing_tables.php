<?php

declare(strict_types=1);

use App\Support\Metering\ProjectSpend;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a project is entitled to, and what it has used.
 *
 * Two tables rather than one because they are written by different things at
 * different rates. A subscription changes when Stripe says so — a handful of
 * times a year, from a webhook. A usage counter changes every time anybody
 * presses anything, from inside a request, under contention. Putting the second
 * on the first would make every article approval take a row lock on the record
 * that decides whether the project may work at all.
 *
 * Neither table is the *truth* about spend. `pipeline_runs` and
 * `assistant_messages` are, and {@see ProjectSpend} adds
 * them up. These counters exist because enforcement needs a race-free
 * increment and a single indexed read, which a sum over two tables gives
 * neither of — and because the truth is reconstructable, a counter that drifts
 * is repairable rather than lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // One per project, and the unique index says so rather than a
            // comment hoping so. Two rows here is two answers to "may this
            // project spend", and whichever one a query happened to order
            // first would win.
            $table->foreignUlid('project_id')->unique()->constrained()->cascadeOnDelete();

            // Who pays. Nullable because a trial has no payer yet, and that is
            // the whole point of it. Null on delete rather than cascade: losing
            // the account that paid must not delete the record that a project
            // was ever entitled.
            $table->foreignId('billing_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('plan');

            // Which price list sold this. A project keeps the entitlements of
            // the version it was opened under, so re-pricing cannot quietly
            // change what somebody already paying is allowed to do.
            $table->unsignedInteger('plan_version')->default(1);

            $table->string('status')->default('trialing');

            // Limits that differ from the plan's, for one customer. This is
            // what Enterprise is: the config row names the shape, this names
            // the numbers. Empty for every self-serve subscription.
            $table->json('limit_overrides');

            // The window the counters are keyed to. Not calendar months: a
            // subscription opened on the 20th must not get a fortnight's quota
            // for a month's money.
            $table->timestamp('period_started_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();

            $table->timestamp('trial_ends_at')->nullable();

            // When dunning runs out and the project pauses. Set from the
            // failed payment, cleared when one succeeds.
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();

            // Stripe's side, filled in by phase 3. Nullable throughout because
            // a hand-assigned plan and a trial are both real subscriptions with
            // nothing at the provider behind them.
            $table->string('stripe_id')->nullable()->unique();
            $table->string('stripe_status')->nullable();
            $table->string('stripe_price')->nullable();

            $table->timestamps();

            // The tick's query: every project that may work right now.
            $table->index(['status', 'period_ends_at']);
        });

        Schema::create('project_usage_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // The period this counter belongs to, stored rather than joined.
            // A counter has to be findable by a plain equality on a date that
            // does not move when a subscription is edited, and it has to
            // survive the subscription row being replaced.
            $table->timestamp('period_started_at');

            $table->string('metric');
            $table->unsignedInteger('used')->default(0);

            $table->timestamps();

            // What makes `increment or insert` atomic: the upsert has one row
            // to conflict with, so two requests approving an article at the
            // same instant produce two, not one.
            $table->unique(['project_id', 'period_started_at', 'metric']);
        });

        Schema::table('pipeline_steps', function (Blueprint $table): void {
            // What the cost ceiling reads, on every gated request: this
            // project's steps since the period began. The existing index is
            // (project_id, step_key), which serves the cost report's grouping
            // and leaves this one scanning every step the project has ever
            // run once a tenant is a few months old.
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_steps', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'created_at']);
        });

        Schema::dropIfExists('project_usage_periods');
        Schema::dropIfExists('project_subscriptions');
    }
};
