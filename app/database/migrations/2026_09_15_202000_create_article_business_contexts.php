<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_business_contexts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('content_item_id');
            $table->ulid('pipeline_run_id')->unique();
            $table->text('prompt');
            $table->string('body_hash', 64)->nullable();
            $table->timestampTz('created_at');
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'content_item_id'])->references(['project_id', 'id'])->on('content_items')->cascadeOnDelete();
            $table->foreign(['project_id', 'pipeline_run_id'])->references(['project_id', 'id'])->on('pipeline_runs')->cascadeOnDelete();
        });
        Schema::create('article_business_fact_references', function (Blueprint $table): void {
            $table->ulid('project_id');
            $table->ulid('context_id');
            $table->ulid('business_fact_id');
            $table->ulid('fact_version_id');
            $table->primary(['context_id', 'fact_version_id']);
            $table->foreign(['project_id', 'context_id'])->references(['project_id', 'id'])->on('article_business_contexts')->cascadeOnDelete();
            $table->foreign(['project_id', 'business_fact_id'])->references(['project_id', 'id'])->on('business_facts');
            $table->foreign(['business_fact_id', 'fact_version_id'])->references(['business_fact_id', 'id'])->on('business_fact_versions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_business_fact_references');
        Schema::dropIfExists('article_business_contexts');
    }
};
