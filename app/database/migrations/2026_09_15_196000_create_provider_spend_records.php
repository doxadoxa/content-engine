<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_runs', fn (Blueprint $table) => $table->unique(['project_id', 'id'], 'provider_run_tenant_identity'));
        Schema::create('provider_spend_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->ulid('pipeline_run_id');
            $table->string('step_key');
            $table->string('status', 30);
            $table->string('provider');
            $table->string('model');
            $table->string('role');
            $table->unsignedInteger('price_list_version');
            $table->char('request_hash', 64);
            $table->char('response_hash', 64)->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cost_micros')->nullable();
            $table->string('cost_basis', 60)->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'created_at']);
            $table->foreign(['project_id', 'pipeline_run_id'])->references(['project_id', 'id'])->on('pipeline_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_spend_records');
        Schema::table('pipeline_runs', fn (Blueprint $table) => $table->dropUnique('provider_run_tenant_identity'));
    }
};
