<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_fact_versions', fn (Blueprint $table) => $table->unique(['project_id', 'id'], 'fact_version_project_identity'));
        Schema::create('ai_accuracy_assessments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->ulid('answer_id');
            $table->string('scope', 30);
            $table->ulid('site_page_id')->nullable();
            $table->ulid('snapshot_id')->nullable();
            $table->ulid('parent_finding_id')->nullable();
            $table->uuid('request_key');
            $table->jsonb('sections');
            $table->jsonb('fact_versions');
            $table->jsonb('source_metadata');
            $table->char('source_hash', 64);
            $table->string('status', 30)->default('queued');
            $table->jsonb('result')->nullable();
            $table->foreignUlid('pipeline_run_id')->nullable()->constrained('pipeline_runs')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->unique(['project_id', 'request_key']);
            $table->foreign(['project_id', 'answer_id'])->references(['project_id', 'id'])->on('ai_sampling_answers')->restrictOnDelete();
            $table->foreign(['project_id', 'site_page_id'])->references(['project_id', 'id'])->on('site_pages')->restrictOnDelete();
            $table->foreign(['project_id', 'site_page_id', 'snapshot_id'])->references(['project_id', 'site_page_id', 'id'])->on('page_snapshots')->restrictOnDelete();
        });
        Schema::create('ai_accuracy_findings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->ulid('assessment_id');
            $table->string('relation', 30);
            $table->ulid('fact_version_id')->nullable();
            $table->jsonb('evidence');
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'assessment_id'])->references(['project_id', 'id'])->on('ai_accuracy_assessments')->restrictOnDelete();
            $table->foreign(['project_id', 'fact_version_id'])->references(['project_id', 'id'])->on('business_fact_versions')->restrictOnDelete();
        });
        Schema::table('ai_accuracy_assessments', function (Blueprint $table): void {
            $table->foreign(['project_id', 'parent_finding_id'])->references(['project_id', 'id'])->on('ai_accuracy_findings')->restrictOnDelete();
        });
        Schema::create('ai_accuracy_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->ulid('finding_id');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 30);
            $table->text('reason');
            $table->timestampsTz();
            $table->foreign(['project_id', 'finding_id'])->references(['project_id', 'id'])->on('ai_accuracy_findings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_accuracy_reviews');
        Schema::table('ai_accuracy_assessments', fn (Blueprint $table) => $table->dropForeign(['project_id', 'parent_finding_id']));
        Schema::dropIfExists('ai_accuracy_findings');
        Schema::dropIfExists('ai_accuracy_assessments');
        Schema::table('business_fact_versions', fn (Blueprint $table) => $table->dropUnique('fact_version_project_identity'));
    }
};
