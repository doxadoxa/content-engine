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
        Schema::table('page_proposal_revisions', function (Blueprint $table): void {
            $table->ulid('editable_snapshot_id')->nullable();
            $table->jsonb('compiled_patch')->nullable();
            $table->foreign(['project_id', 'site_page_id', 'editable_snapshot_id'], 'proposal_editable_snapshot_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_snapshots');
        });
        Schema::table('page_publications', function (Blueprint $table): void {
            $table->timestampTz('recovered_at')->nullable();
            $table->unique(['project_id', 'site_page_id', 'id'], 'publication_page_identity');
        });
        Schema::create('page_publication_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('publication_id');
            $table->ulid('site_page_id');
            $table->ulid('channel_id');
            $table->ulid('recovery_of_id')->nullable()->unique();
            $table->string('kind');
            $table->string('status');
            $table->uuid('delivery_id')->unique();
            $table->text('request_body');
            $table->char('request_hash', 64);
            $table->jsonb('destination');
            $table->ulid('before_snapshot_id')->nullable();
            $table->ulid('after_snapshot_id')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('authorized_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('dispatch_started_at')->nullable();
            $table->timestampTz('retry_at')->nullable()->index();
            $table->timestampTz('committed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->ulid('verification_snapshot_id')->nullable();
            $table->jsonb('verification_results')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'id']);
            $table->unique(['project_id', 'site_page_id', 'id'], 'native_operation_page_identity');
            $table->foreign(['project_id', 'site_page_id', 'publication_id'], 'native_operation_publication_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_publications');
            $table->foreign(['project_id', 'channel_id'])->references(['project_id', 'id'])->on('channels');
            $table->foreign(['project_id', 'site_page_id', 'recovery_of_id'], 'native_recovery_same_page_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_publication_operations');
            foreach (['before_snapshot_id', 'after_snapshot_id', 'verification_snapshot_id'] as $field) {
                $table->foreign(['project_id', 'site_page_id', $field], 'native_operation_'.$field.'_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_snapshots');
            }
        });
        DB::statement("CREATE UNIQUE INDEX native_publication_once ON page_publication_operations (publication_id) WHERE kind = 'publish'");
        Schema::create('page_publication_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('operation_id');
            $table->unsignedInteger('number');
            $table->string('action');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('outcome');
            $table->text('detail')->nullable();
            $table->timestampsTz();
            $table->unique(['operation_id', 'number']);
            $table->foreign(['project_id', 'operation_id'])->references(['project_id', 'id'])->on('page_publication_operations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_publication_attempts');
        Schema::dropIfExists('page_publication_operations');
        Schema::table('page_publications', function (Blueprint $table): void {
            $table->dropUnique('publication_page_identity');
            $table->dropColumn('recovered_at');
        });
        Schema::table('page_proposal_revisions', function (Blueprint $table): void {
            $table->dropForeign('proposal_editable_snapshot_fk');
            $table->dropColumn(['editable_snapshot_id', 'compiled_patch']);
        });
    }
};
