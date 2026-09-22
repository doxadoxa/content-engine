<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_maintenance_checks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_page_id')->constrained();
            $table->uuid('request_key');
            $table->jsonb('specification');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignUlid('source_snapshot_id')->nullable()->constrained('page_snapshots');
            $table->foreignUlid('pipeline_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('queued');
            $table->text('reason')->nullable();
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'request_key', 'site_page_id']);
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'site_page_id'])->references(['project_id', 'id'])->on('site_pages');
        });
        Schema::create('fact_maintenance_results', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('check_id')->unique();
            $table->jsonb('assessment');
            $table->jsonb('source_coverage');
            $table->jsonb('comparisons');
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'check_id'])->references(['project_id', 'id'])->on('fact_maintenance_checks');
        });
        Schema::create('fact_maintenance_claims', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('check_id');
            $table->foreignUlid('site_page_id')->constrained();
            $table->foreignUlid('source_snapshot_id')->constrained('page_snapshots');
            $table->jsonb('source');
            $table->text('exact_quote');
            $table->unsignedInteger('start_codepoint');
            $table->unsignedInteger('end_codepoint');
            $table->string('relation', 24);
            $table->ulid('fact_version_id')->nullable();
            $table->text('reason');
            $table->jsonb('reference_ids');
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'check_id'])->references(['project_id', 'id'])->on('fact_maintenance_checks');
            $table->foreign(['project_id', 'fact_version_id'])->references(['project_id', 'id'])->on('business_fact_versions');
        });
        Schema::create('fact_maintenance_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('claim_id');
            $table->uuid('request_key');
            $table->string('action', 32);
            $table->foreignId('actor_id')->constrained('users');
            $table->text('reason');
            $table->jsonb('evidence');
            $table->timestampsTz();
            $table->unique(['project_id', 'request_key']);
            $table->foreign(['project_id', 'claim_id'])->references(['project_id', 'id'])->on('fact_maintenance_claims');
        });
        Schema::create('fact_usage_impacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_page_id')->constrained();
            $table->ulid('previous_fact_version_id');
            $table->ulid('new_fact_version_id');
            $table->string('origin_type', 24);
            $table->ulid('origin_id');
            $table->jsonb('evidence');
            $table->timestampsTz();
            $table->unique(['new_fact_version_id', 'origin_type', 'origin_id']);
            $table->foreign(['project_id', 'previous_fact_version_id'])->references(['project_id', 'id'])->on('business_fact_versions');
            $table->foreign(['project_id', 'new_fact_version_id'])->references(['project_id', 'id'])->on('business_fact_versions');
        });
    }

    public function down(): void
    {
        foreach (['fact_usage_impacts', 'fact_maintenance_reviews', 'fact_maintenance_claims', 'fact_maintenance_results', 'fact_maintenance_checks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
