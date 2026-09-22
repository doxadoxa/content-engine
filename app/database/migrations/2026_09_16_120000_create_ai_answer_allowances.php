<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_answer_reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('sampling_cell_id')->unique();
            $table->timestampTz('period_started_at');
            $table->timestampTz('period_ends_at');
            $table->string('status', 20);
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['project_id', 'sampling_cell_id'])->references(['project_id', 'id'])->on('ai_sampling_cells');
            $table->index(['project_id', 'period_started_at', 'status']);
        });
        Schema::create('ai_sampling_cycles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('period_started_at');
            $table->timestampTz('period_ends_at');
            $table->unsignedSmallInteger('slot');
            $table->ulid('sampling_run_id')->nullable();
            $table->foreignUlid('pipeline_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('prompt_attempted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'period_started_at', 'slot']);
            $table->foreign(['project_id', 'sampling_run_id'])->references(['project_id', 'id'])->on('ai_sampling_runs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_sampling_cycles');
        Schema::dropIfExists('ai_answer_reservations');
    }
};
