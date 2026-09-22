<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_schedules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('publish_at')->index();
            $table->string('local_date', 10);
            $table->string('local_time', 5);
            $table->string('timezone');
            $table->string('mode');
            $table->string('status')->index();
            $table->string('origin');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->text('blocked_reason')->nullable();
            $table->foreignUlid('delivery_id')->nullable()->constrained('webhook_deliveries')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->foreignUlid('article_schedule_id')->nullable()->constrained('article_schedules')->nullOnDelete();
            $table->unsignedInteger('article_schedule_version')->nullable();
            $table->timestampTz('article_attempt_started_at')->nullable();
        });
        Schema::create('article_approval_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('period_started_at')->nullable();
            $table->unsignedSmallInteger('units');
            $table->string('policy');
            $table->timestampsTz();
        });
        // Adoption never creates schedules or charges earlier approvals. The
        // exact approval time was not recorded by the previous implementation.
        DB::table('content_items')->whereNull('social_band')->where(function ($query): void {
            $query->whereIn('state', ['approved', 'published', 'refreshing'])->orWhereNotNull('published_at');
        })->orderBy('id')->each(function (object $item): void {
            DB::table('article_approval_records')->insert([
                'id' => (string) Str::ulid(), 'project_id' => $item->project_id, 'content_item_id' => $item->id,
                'accepted_at' => null, 'units' => 0, 'policy' => 'approved_before_article_ledger',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('article_schedule_id');
            $table->dropColumn(['article_schedule_version', 'article_attempt_started_at']);
        });
        Schema::dropIfExists('article_schedules');
        Schema::dropIfExists('article_approval_records');
    }
};
