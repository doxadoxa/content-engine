<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per pipeline execution (§2): steps, tokens, cost, latency.
 *
 * Created in phase 2 and written to in phase 3. It is here now because §1 makes
 * metering a day-one requirement — "решения по моделям на шаг пайплайна — по
 * данным" — and a metering table added after the pipelines run is a table with
 * no history in it when the first model decision has to be made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // Runs outlive the unit they were about: deleting a draft should
            // not erase what it cost to produce.
            $table->foreignUlid('content_item_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('pipeline');
            $table->string('status')->default('running');

            // Per-step detail — name, model, tokens, latency, retries. Read as
            // a whole when someone opens a run, never aggregated across rows in
            // SQL, so it stays a document rather than a child table.
            $table->json('steps');

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);

            // Micro-USD as an integer. Per-step costs are fractions of a cent
            // and a float column turns a month of them into an argument about
            // rounding.
            $table->bigInteger('cost_micros')->default(0);

            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'pipeline', 'created_at']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_runs');
    }
};
