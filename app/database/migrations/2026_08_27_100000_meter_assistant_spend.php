<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hole in the meter.
 *
 * `assistant_messages` has recorded `input_tokens` and `output_tokens` since it
 * existed and never recorded what they cost — so the one contour a customer can
 * drive as hard as they like was invisible to `pipeline:cost`, to the metering
 * screen, and therefore to anything built on either. `ConversationGateway`'s
 * own docblock promised the opposite: "it reports what the turn cost, in the
 * same shape ModelResponse does, so a conversation is metered exactly like a
 * pipeline step". The gateway kept that promise; nothing downstream of it did.
 *
 * The columns are `pipeline_steps`' columns, deliberately and name for name.
 * Two tables that answer the same question in different shapes is how a report
 * comes to sum one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table): void {
            // Null on the two rows out of three that call no model: what a
            // person typed, and what a tool returned. Same reading as on a
            // pipeline step that spends nothing, and worth being able to see.
            $table->string('provider')->nullable()->after('output_tokens');
            $table->string('model')->nullable()->after('provider');
            $table->bigInteger('cost_micros')->default(0)->after('model');
            $table->unsignedInteger('latency_ms')->nullable()->after('cost_micros');

            // Which price list priced this turn, for the reason
            // `pipeline_runs` carries the same column: re-pricing publishes a
            // new answer knowingly rather than quietly restating the past.
            $table->unsignedInteger('price_list_version')->default(1)->after('latency_ms');
        });

        Schema::table('assistant_messages', function (Blueprint $table): void {
            // The spend query: this project's turns in a window. Every caller
            // that asks what a project has cost asks in exactly this shape.
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'created_at']);
            $table->dropColumn([
                'provider',
                'model',
                'cost_micros',
                'latency_ms',
                'price_list_version',
            ]);
        });
    }
};
