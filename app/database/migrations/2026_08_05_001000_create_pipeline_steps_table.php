<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The step becomes a row.
 *
 * Phase 2 gave `pipeline_runs` a `steps` json column, on the reasoning that
 * per-step detail is read as a whole and never aggregated in SQL. Phase 3 makes
 * that wrong on both counts. A step now carries mutable execution state —
 * status, attempt, claim — that two workers compare and set against each other,
 * and a document rewritten wholesale cannot express "claim this one step if
 * nobody else has"; and §3.4 asks for cost broken down by step across a project,
 * which is an aggregate over exactly these rows. So the column goes and the
 * table arrives. Nothing wrote to the column, so there is nothing to migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_runs', function (Blueprint $table): void {
            $table->dropColumn(['steps', 'error']);
        });

        Schema::table('pipeline_runs', function (Blueprint $table): void {
            $table->unsignedInteger('pipeline_version')->default(1)->after('pipeline');

            // What the run was started with, and what steps have accumulated
            // for each other. Separate because one is an argument and the other
            // is state: re-running a pipeline means the same input and an empty
            // context.
            $table->json('input');
            $table->json('context');

            // Which price list priced this run (§3.4). Recomputing costs under
            // a new list must produce a new answer knowingly, not quietly
            // restate what last quarter cost.
            $table->unsignedInteger('price_list_version')->default(1);

            // Structured now rather than a message: the panel of phase 7 renders
            // it, and "which step, and was it worth retrying" are fields.
            $table->json('error')->nullable();
            $table->string('failed_step_key')->nullable();
        });

        Schema::create('pipeline_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('pipeline_run_id')->constrained()->cascadeOnDelete();

            $table->string('step_key');

            // Topological position, computed when the run is created. The DAG
            // decides what may run; this only decides what a human reads first.
            $table->unsignedInteger('position');

            $table->string('status')->default('pending');

            // Doubles as the optimistic-lock version. A claim is
            // `where attempt = N ... set attempt = N + 1`, so two deliveries of
            // one step cannot both win — see PipelineRunner::claim().
            $table->unsignedInteger('attempt')->default(0);

            // Metering (§3.4). Null on steps that call no model at all, which is
            // most of them and is worth being able to see.
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('role')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->bigInteger('cost_micros')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();

            $table->json('output')->nullable();
            $table->json('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // §3.1: a step is idempotent by (run, step_key). One row per pair is
            // what makes that enforceable rather than aspirational — a repeat
            // delivery has nowhere to write a second one.
            $table->unique(['pipeline_run_id', 'step_key']);

            // The scheduler's query: this run's steps, and the cost report's:
            // this project's steps by key.
            $table->index(['pipeline_run_id', 'status']);
            $table->index(['project_id', 'step_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_steps');

        Schema::table('pipeline_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'pipeline_version',
                'input',
                'context',
                'price_list_version',
                'error',
                'failed_step_key',
            ]);
        });

        Schema::table('pipeline_runs', function (Blueprint $table): void {
            $table->json('steps');
            $table->text('error')->nullable();
        });
    }
};
