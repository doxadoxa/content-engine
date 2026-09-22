<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_correction_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->ulid('finding_id');
            $table->ulid('owned_page_finding_id')->nullable();
            $table->ulid('opportunity_id')->nullable();
            $table->string('kind', 30);
            $table->text('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'finding_id'])->references(['project_id', 'id'])->on('ai_accuracy_findings')->restrictOnDelete();
            $table->foreign(['project_id', 'owned_page_finding_id'])->references(['project_id', 'id'])->on('ai_accuracy_findings')->restrictOnDelete();
            $table->foreign(['project_id', 'opportunity_id'])->references(['project_id', 'id'])->on('page_opportunities')->restrictOnDelete();
        });
        Schema::create('ai_correction_updates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->ulid('action_id');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30);
            $table->text('note');
            $table->timestampsTz();
            $table->foreign(['project_id', 'action_id'])->references(['project_id', 'id'])->on('ai_correction_actions')->restrictOnDelete();
        });
        Schema::create('ai_correction_rechecks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->ulid('action_id');
            $table->ulid('sampling_run_id');
            $table->timestampsTz();
            $table->unique(['action_id', 'sampling_run_id']);
            $table->foreign(['project_id', 'action_id'])->references(['project_id', 'id'])->on('ai_correction_actions')->restrictOnDelete();
            $table->foreign(['project_id', 'sampling_run_id'])->references(['project_id', 'id'])->on('ai_sampling_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_correction_rechecks');
        Schema::dropIfExists('ai_correction_updates');
        Schema::dropIfExists('ai_correction_actions');
    }
};
