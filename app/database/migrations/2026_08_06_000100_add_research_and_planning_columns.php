<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Research and Planning need to write (§3.2, §4.1–4.3).
 *
 * An "idea" is not a new entity. §2's state machine already starts a content
 * unit at `idea`, and a unit already carries a target query, difficulty and
 * volume — so research produces units in `idea` with no plan, and planning is
 * what gives them a plan and a date. A separate ideas table would have been the
 * same columns with a migration between them later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            // §4.3: publishing frequency is project configuration, not a
            // constant. "Reasonable frequency" is the stated mitigation for
            // Google's scaled-content abuse policy (§1), and a mitigation that
            // is hard-coded is a mitigation nobody can tune per project.
            $table->unsignedSmallInteger('weekly_target')->default(3);

            // What research asks the keyword source about, and the market it
            // asks for. Volume for "window cleaning" in Portugal and in the US
            // are different numbers about different businesses.
            // Defaulted rather than backfilled: the column is NOT NULL and these
            // tables already have rows, and "no seeds yet" is an empty list
            // rather than an unknown.
            $table->json('research_seeds')->default('[]');
            $table->string('market', 8)->default('us');
        });

        Schema::table('content_items', function (Blueprint $table): void {
            // Where research put it. The cluster is what keeps a plan from
            // being twelve articles about the same thing in different words.
            $table->string('intent')->nullable();
            $table->string('cluster')->nullable();

            // The calendar (§4.2). Null while an idea is only an idea.
            $table->date('scheduled_for')->nullable();

            // §4.3: the planner must mark units that need real business data —
            // prices, cases, local specifics — because generation will not ask
            // for what nobody flagged, and §1 makes original data the other
            // half of the scaled-content mitigation.
            $table->boolean('needs_original_data')->default(false);

            // Which channels this unit is planned to be repurposed to. The
            // derivatives themselves are phase 8; the plan only records that
            // they are coming, which is what makes the month's cost estimable.
            $table->json('planned_derivatives')->default('[]');

            $table->index(['project_id', 'scheduled_for']);
            $table->index(['project_id', 'cluster']);
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'scheduled_for']);
            $table->dropIndex(['project_id', 'cluster']);
            $table->dropColumn([
                'intent',
                'cluster',
                'scheduled_for',
                'needs_original_data',
                'planned_derivatives',
            ]);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['weekly_target', 'research_seeds', 'market']);
        });
    }
};
