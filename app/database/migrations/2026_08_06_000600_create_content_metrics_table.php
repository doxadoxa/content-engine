<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened after publication (§9.1).
 *
 * A row per unit per day, because a trend is the only thing that can say
 * "degrading" — a single reading says "low", which is what a new article always
 * looks like.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_metrics', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_item_id')->constrained()->cascadeOnDelete();

            $table->date('measured_on');

            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            // Average position, to one decimal. Stored ×10 as an integer so a
            // month of them adds up without floating point drift.
            $table->unsignedSmallInteger('position_tenths')->nullable();
            $table->boolean('indexed')->default(false);

            $table->timestamps();

            // One reading per unit per day. Re-fetching a day must correct it
            // rather than double it — the API restates history as it settles.
            $table->unique(['content_item_id', 'measured_on']);
            $table->index(['project_id', 'measured_on']);
        });

        Schema::table('content_items', function (Blueprint $table): void {
            // §9.3: whether the brand shows up in an AI answer for the target
            // query. The metric the whole GEO layer was built for — until now
            // it was optimised blind.
            $table->json('citations')->default('{}');
            $table->timestamp('citations_checked_at')->nullable();

            // Set when the feedback loop decides this has decayed, cleared when
            // a refresh republishes it. The refresh queue is a column rather
            // than a table because it is one boolean fact about a unit.
            $table->timestamp('refresh_due_at')->nullable();
            $table->string('refresh_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn(['citations', 'citations_checked_at', 'refresh_due_at', 'refresh_reason']);
        });

        Schema::dropIfExists('content_metrics');
    }
};
