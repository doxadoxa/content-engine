<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_sampling_sets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('configuration');
            $table->char('configuration_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_reason');
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->unique(['project_id', 'version']);
        });
        Schema::create('ai_sampling_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('sampling_set_id');
            $table->string('request_key', 100);
            $table->string('purpose', 24);
            $table->string('status', 24)->default('queued');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->ulid('recheck_of_run_id')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->unique(['project_id', 'request_key']);
            $table->foreign(['project_id', 'sampling_set_id'])->references(['project_id', 'id'])->on('ai_sampling_sets');
            $table->foreign(['project_id', 'recheck_of_run_id'])->references(['project_id', 'id'])->on('ai_sampling_runs');
        });
        Schema::create('ai_sampling_cells', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('sampling_run_id');
            $table->char('cell_key', 64);
            $table->jsonb('specification');
            $table->jsonb('inventory')->nullable();
            $table->timestampTz('inventory_checked_at')->nullable();
            $table->string('status', 24)->default('queued');
            $table->text('reason')->nullable();
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->foreignUlid('pipeline_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->unique(['sampling_run_id', 'cell_key']);
            $table->foreign(['project_id', 'sampling_run_id'])->references(['project_id', 'id'])->on('ai_sampling_runs');
        });
        Schema::create('ai_sampling_answers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('sampling_cell_id')->unique();
            $table->longText('full_text');
            $table->jsonb('sections');
            $table->jsonb('citations');
            $table->jsonb('metadata');
            $table->char('content_hash', 64);
            $table->boolean('mentioned_in_text')->nullable();
            $table->boolean('cited_own_site')->nullable();
            $table->string('resolved_model')->nullable();
            $table->timestampTz('received_at');
            $table->bigInteger('total_cost_micros')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'sampling_cell_id'])->references(['project_id', 'id'])->on('ai_sampling_cells');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_sampling_answers');
        Schema::dropIfExists('ai_sampling_cells');
        Schema::dropIfExists('ai_sampling_runs');
        Schema::dropIfExists('ai_sampling_sets');
    }
};
