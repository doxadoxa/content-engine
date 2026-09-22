<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_effort_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users');
            $table->uuid('request_id');
            $table->string('fingerprint', 64);
            $table->string('category', 40);
            $table->unsignedInteger('minutes');
            $table->unsignedInteger('hourly_usd_cents')->nullable();
            $table->timestampTz('happened_at');
            $table->text('note');
            $table->ulid('supersedes_id')->nullable()->unique();
            $table->timestampsTz();
            $table->unique(['project_id', 'request_id']);
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'supersedes_id'])->references(['project_id', 'id'])->on('service_effort_entries');
            $table->index(['project_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_effort_entries');
    }
};
