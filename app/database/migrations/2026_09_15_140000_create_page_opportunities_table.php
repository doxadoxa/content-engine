<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_opportunities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('site_page_id');
            $table->string('kind', 40);
            $table->text('diagnosed_issue');
            $table->text('suggested_scope');
            $table->jsonb('evidence_snapshot');
            $table->string('confidence', 16);
            $table->string('effort', 16);
            $table->jsonb('ranking_factors');
            $table->jsonb('missing_fact_questions');
            $table->jsonb('overlap_page_ids');
            $table->string('status', 24)->default('open');
            $table->text('dismissal_reason')->nullable();
            $table->string('fingerprint', 64);
            $table->timestamp('diagnosed_at');
            $table->timestamps();
            $table->foreign(['project_id', 'site_page_id'])->references(['project_id', 'id'])->on('site_pages')->cascadeOnDelete();
            $table->unique(['project_id', 'id']);
            $table->unique(['project_id', 'fingerprint']);
            $table->index(['project_id', 'status']);
        });
        Schema::create('page_opportunity_scans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('tracked_pages');
            $table->unsignedInteger('open_count');
            $table->jsonb('notes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_opportunity_scans');
        Schema::dropIfExists('page_opportunities');
    }
};
