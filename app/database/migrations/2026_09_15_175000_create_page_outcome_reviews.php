<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_opportunities', fn (Blueprint $table) => $table->unique(['project_id', 'site_page_id', 'id'], 'opportunity_page_identity_unique'));
        Schema::create('page_outcome_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('publication_id');
            $table->ulid('site_page_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision');
            $table->text('reason');
            $table->ulid('source_snapshot_id');
            $table->ulid('next_opportunity_id')->nullable();
            $table->jsonb('evidence');
            $table->timestamps();
            $table->unique(['project_id', 'id']);
            $table->index(['publication_id', 'id']);
            $table->foreign(['project_id', 'site_page_id', 'publication_id'], 'outcome_publication_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_publications');
            $table->foreign(['project_id', 'site_page_id', 'source_snapshot_id'], 'outcome_snapshot_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_snapshots');
            $table->foreign(['project_id', 'site_page_id', 'next_opportunity_id'], 'outcome_next_opportunity_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_opportunities');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_outcome_reviews');
        Schema::table('page_opportunities', fn (Blueprint $table) => $table->dropUnique('opportunity_page_identity_unique'));
    }
};
