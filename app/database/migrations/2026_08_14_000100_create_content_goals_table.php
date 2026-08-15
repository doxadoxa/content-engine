<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a month of social is aiming at.
 *
 * The engine could describe a month in detail — a summary, pillars, channel
 * roles, twenty ideas with evidence — and could not say what any of it was
 * *for*. `content_plans` carries a strategy blob and a version, and neither is
 * a number anybody can be held to, so "accept this proposal" was a decision
 * with no consequence attached and the Studio had nothing to report progress
 * against.
 *
 * **Its own table rather than columns on `content_plans`.** Two reasons, and
 * the second is the load-bearing one. A plan is versioned by the assistant and
 * replaced whenever the operator refines it; a goal is set once by a person and
 * must survive every refinement, or the act of asking for a better month
 * silently discards what the month was for. And a goal is confirmed
 * independently — `confirmed_at` is a human decision about this row, not about
 * whichever proposal happened to be live when it was made.
 *
 * One goal per project-month, which is the same grain a plan uses. Enforced
 * rather than assumed: two goals for one month is two headers disagreeing about
 * the same week, and there is no sensible tiebreak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_goals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // The first of the month, matching `content_plans.month`. Stored
            // rather than derived through the plan because a goal may be set
            // for a month the assistant has not proposed yet — deciding what
            // you want before asking for a plan is the ordinary order, not an
            // edge case.
            $table->date('month');

            // App\Enums\SocialKpi. One, deliberately; see the enum for why a
            // scorecard of three is the same as no goal at all.
            $table->string('kpi');

            // What the operator is promising to move it by. Unsigned and
            // non-zero at the application layer: a target of nothing is not a
            // goal, and the form refuses it rather than the column pretending
            // it is meaningful.
            $table->unsignedInteger('target');

            // Posts per week. Defaults from `projects.weekly_target` at write
            // time rather than being read through to it, because the project's
            // number is about the whole engine — articles included — and a
            // social cadence that silently changed when somebody edited the
            // project's volume would be a goal moving under the operator.
            $table->unsignedSmallInteger('cadence');

            // Four weekly objectives, written by the assistant. Empty until
            // something writes them, and the header renders the week number
            // without them — the week is arithmetic on the month, and only the
            // prose needs a model.
            $table->jsonb('weeks')->default('[]');

            // Null while the operator is still deciding. A goal that exists but
            // is unconfirmed is a draft of an intention, and the Overview shows
            // the setup step rather than a header counting against a number
            // nobody has agreed to.
            $table->timestampTz('confirmed_at')->nullable();

            $table->timestampsTz();

            $table->unique(['project_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_goals');
    }
};
