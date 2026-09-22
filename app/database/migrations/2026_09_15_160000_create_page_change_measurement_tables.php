<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_change_baselines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('proposal_id')->constrained('page_proposals');
            $table->foreignUlid('revision_id')->unique()->constrained('page_proposal_revisions');
            $table->foreignUlid('site_page_id')->constrained();
            $table->foreignUlid('source_snapshot_id')->constrained('page_snapshots');
            $table->foreignUlid('purchase_source_id')->nullable()->constrained();
            $table->timestampTz('pinned_at');
            $table->jsonb('evidence');
            $table->timestamps();
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'site_page_id'])->references(['project_id', 'id'])->on('site_pages');
            $table->foreign(['project_id', 'revision_id'])->references(['project_id', 'id'])->on('page_proposal_revisions');
        });
        Schema::create('page_change_followups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('baseline_id')->constrained('page_change_baselines');
            $table->foreignUlid('publication_id')->constrained('page_publications');
            $table->foreignUlid('site_page_id')->constrained();
            $table->unsignedSmallInteger('days');
            $table->timestampTz('verified_at');
            $table->date('window_from');
            $table->date('window_to');
            $table->date('due_on');
            $table->timestamps();
            $table->unique(['publication_id', 'days']);
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'baseline_id'])->references(['project_id', 'id'])->on('page_change_baselines');
            $table->foreign(['project_id', 'publication_id'])->references(['project_id', 'id'])->on('page_publications');
        });
        Schema::create('page_change_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('followup_id')->constrained('page_change_followups');
            $table->timestampTz('captured_at');
            $table->string('status');
            $table->jsonb('evidence');
            $table->char('evidence_hash', 64);
            $table->timestamps();
            $table->unique(['followup_id', 'evidence_hash']);
            $table->foreign(['project_id', 'followup_id'])->references(['project_id', 'id'])->on('page_change_followups');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_change_reviews');
        Schema::dropIfExists('page_change_followups');
        Schema::dropIfExists('page_change_baselines');
    }
};
