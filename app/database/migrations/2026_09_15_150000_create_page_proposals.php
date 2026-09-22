<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_snapshots', fn (Blueprint $table) => $table->unique(['project_id', 'site_page_id', 'id'], 'page_snapshot_page_identity'));
        Schema::create('page_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('opportunity_id')->unique();
            $table->ulid('site_page_id');
            $table->ulid('current_revision_id')->nullable();
            $table->ulid('approved_revision_id')->nullable();
            $table->string('status')->default('drafting');
            $table->text('invalidation_reason')->nullable();
            $table->uuid('generation_id')->nullable();
            $table->ulid('generation_snapshot_id')->nullable();
            $table->jsonb('generation_context')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->unique(['project_id', 'site_page_id', 'id'], 'page_proposal_page_identity');
            $table->foreign(['project_id', 'opportunity_id'])->references(['project_id', 'id'])->on('page_opportunities');
            $table->foreign(['project_id', 'site_page_id'])->references(['project_id', 'id'])->on('site_pages');
            $table->foreign(['project_id', 'site_page_id', 'generation_snapshot_id'], 'proposal_generation_snapshot_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_snapshots');
        });
        Schema::create('page_proposal_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('proposal_id');
            $table->ulid('site_page_id');
            $table->ulid('source_snapshot_id');
            $table->unsignedInteger('number');
            $table->text('canonical_url');
            $table->string('locale', 35);
            $table->jsonb('changes');
            $table->char('patch_hash', 64);
            $table->jsonb('evidence_snapshot');
            $table->jsonb('measurement_plan');
            $table->jsonb('missing_facts');
            $table->text('no_change_reason')->nullable();
            $table->text('revision_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('pipeline_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['proposal_id', 'number']);
            $table->unique(['project_id', 'id']);
            $table->unique(['project_id', 'proposal_id', 'id'], 'proposal_revision_identity');
            $table->foreign(['project_id', 'site_page_id', 'proposal_id'], 'proposal_revision_page_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_proposals');
            $table->foreign(['project_id', 'site_page_id', 'source_snapshot_id'], 'proposal_revision_snapshot_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_snapshots');
        });
        Schema::table('page_proposals', function (Blueprint $table): void {
            $table->foreign(['project_id', 'id', 'current_revision_id'], 'proposal_current_revision_fk')->references(['project_id', 'proposal_id', 'id'])->on('page_proposal_revisions');
            $table->foreign(['project_id', 'id', 'approved_revision_id'], 'proposal_approved_revision_fk')->references(['project_id', 'proposal_id', 'id'])->on('page_proposal_revisions');
        });
        Schema::create('page_proposal_facts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('proposal_id');
            $table->ulid('revision_id');
            $table->ulid('business_fact_id');
            $table->ulid('fact_version_id');
            $table->timestampsTz();
            $table->unique(['revision_id', 'business_fact_id']);
            $table->foreign(['project_id', 'proposal_id', 'revision_id'], 'proposal_fact_revision_fk')->references(['project_id', 'proposal_id', 'id'])->on('page_proposal_revisions');
            $table->foreign(['project_id', 'business_fact_id'])->references(['project_id', 'id'])->on('business_facts');
            $table->foreign(['business_fact_id', 'fact_version_id'])->references(['business_fact_id', 'id'])->on('business_fact_versions');
        });
        Schema::create('page_proposal_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('proposal_id');
            $table->ulid('revision_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('reason')->nullable();
            $table->unsignedInteger('active_seconds')->default(0);
            $table->jsonb('corrections')->nullable();
            $table->timestampsTz();
            $table->foreign(['project_id', 'proposal_id'])->references(['project_id', 'id'])->on('page_proposals');
            $table->foreign(['project_id', 'proposal_id', 'revision_id'], 'proposal_review_revision_fk')->references(['project_id', 'proposal_id', 'id'])->on('page_proposal_revisions');
        });
        Schema::create('page_publications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('proposal_id');
            $table->ulid('revision_id')->unique();
            $table->ulid('site_page_id');
            $table->uuid('delivery_id')->unique();
            $table->string('mode')->default('assisted');
            $table->string('status')->default('awaiting_operator');
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('authorized_at');
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('applied_by_name')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->text('application_note')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->ulid('verification_snapshot_id')->nullable();
            $table->jsonb('verification_results')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'site_page_id', 'proposal_id'], 'publication_proposal_page_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_proposals');
            $table->foreign(['project_id', 'proposal_id', 'revision_id'], 'publication_revision_fk')->references(['project_id', 'proposal_id', 'id'])->on('page_proposal_revisions');
            $table->foreign(['project_id', 'site_page_id', 'verification_snapshot_id'], 'publication_verified_snapshot_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_snapshots');
        });
        Schema::create('page_publication_checks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('publication_id');
            $table->foreignUlid('snapshot_id')->nullable()->constrained('page_snapshots');
            $table->string('status');
            $table->jsonb('results');
            $table->timestampsTz();
            $table->foreign(['project_id', 'publication_id'])->references(['project_id', 'id'])->on('page_publications');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_publication_checks');
        Schema::dropIfExists('page_publications');
        Schema::dropIfExists('page_proposal_reviews');
        Schema::dropIfExists('page_proposal_facts');
        Schema::table('page_proposals', function (Blueprint $table): void {
            $table->dropForeign('proposal_current_revision_fk');
            $table->dropForeign('proposal_approved_revision_fk');
        });
        Schema::dropIfExists('page_proposal_revisions');
        Schema::dropIfExists('page_proposals');
        Schema::table('page_snapshots', fn (Blueprint $table) => $table->dropUnique('page_snapshot_page_identity'));
    }
};
