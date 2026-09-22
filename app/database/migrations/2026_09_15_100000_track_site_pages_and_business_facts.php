<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_pages', function (Blueprint $table): void {
            $table->timestamp('tracked_at')->nullable()->index();
            $table->text('canonical_url')->nullable();
            $table->string('canonical_hash', 64)->nullable();
            $table->string('locale', 35)->nullable();
            $table->foreignUlid('content_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cms_object_type')->nullable();
            $table->string('cms_object_id')->nullable();
            $table->unique(['project_id', 'canonical_hash']);
            $table->unique(['project_id', 'content_item_id']);
            $table->unique(['project_id', 'id']);
        });

        DB::statement('ALTER TABLE content_items ADD CONSTRAINT content_items_project_id_id_unique UNIQUE (project_id, id)');
        DB::statement('ALTER TABLE site_pages ADD CONSTRAINT site_pages_content_in_project_foreign FOREIGN KEY (project_id, content_item_id) REFERENCES content_items (project_id, id)');
        DB::statement('ALTER TABLE site_pages ADD CONSTRAINT site_pages_channel_in_project_foreign FOREIGN KEY (project_id, channel_id) REFERENCES channels (project_id, id)');

        Schema::create('page_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('site_page_id');
            $table->string('source_kind', 24);
            $table->text('source_url');
            $table->timestamp('captured_at');
            $table->string('revision');
            $table->string('content_hash', 64);
            $table->jsonb('fields');
            $table->jsonb('editable_fields');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->foreign(['project_id', 'site_page_id'])->references(['project_id', 'id'])->on('site_pages')->cascadeOnDelete();
            $table->index(['site_page_id', 'captured_at']);
        });

        Schema::create('business_facts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->ulid('current_version_id')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'id']);
        });

        Schema::create('business_fact_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('business_fact_id');
            $table->unsignedInteger('version');
            $table->text('statement');
            $table->text('source_url')->nullable();
            $table->text('source_note');
            $table->string('status', 24);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('review_due_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign(['project_id', 'business_fact_id'])->references(['project_id', 'id'])->on('business_facts')->cascadeOnDelete();
            $table->unique(['business_fact_id', 'version']);
            $table->unique(['business_fact_id', 'id']);
        });
        Schema::table('business_facts', function (Blueprint $table): void {
            $table->foreign(['id', 'current_version_id'])->references(['business_fact_id', 'id'])->on('business_fact_versions')->deferrable()->initiallyImmediate();
        });
    }

    public function down(): void
    {
        Schema::table('business_facts', fn (Blueprint $table) => $table->dropForeign(['id', 'current_version_id']));
        Schema::dropIfExists('business_fact_versions');
        Schema::dropIfExists('business_facts');
        Schema::dropIfExists('page_snapshots');
        DB::statement('ALTER TABLE site_pages DROP CONSTRAINT site_pages_content_in_project_foreign');
        DB::statement('ALTER TABLE site_pages DROP CONSTRAINT site_pages_channel_in_project_foreign');
        DB::statement('ALTER TABLE content_items DROP CONSTRAINT content_items_project_id_id_unique');
        Schema::table('site_pages', function (Blueprint $table): void {
            $table->dropForeign(['content_item_id']);
            $table->dropForeign(['channel_id']);
            $table->dropUnique(['project_id', 'canonical_hash']);
            $table->dropUnique(['project_id', 'content_item_id']);
            $table->dropUnique(['project_id', 'id']);
            $table->dropColumn(['tracked_at', 'canonical_url', 'canonical_hash', 'locale', 'content_item_id', 'channel_id', 'cms_object_type', 'cms_object_id']);
        });
    }
};
