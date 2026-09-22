<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_planning_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_plan_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('period_started_at');
            $table->timestampTz('period_ends_at');
            $table->string('timezone');
            $table->timestampTz('plan_counted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'period_started_at']);
        });
        Schema::table('content_items', function (Blueprint $table): void {
            $table->foreignUlid('article_planning_period_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('planned_publication_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('article_planning_period_id');
            $table->dropColumn('planned_publication_at');
        });
        Schema::dropIfExists('article_planning_periods');
    }
};
