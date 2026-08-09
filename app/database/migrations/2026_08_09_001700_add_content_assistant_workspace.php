<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The proposal-first content workspace.
 *
 * `content_plans` remains the one month visible in the calendar. The assistant
 * adds a versioned strategy to it, while `content_ideas` supplies the semantic
 * layer between that strategy and one draft per channel. Ideas are immutable
 * per proposal version: replacing rows would orphan the explanation behind a
 * draft that was generated before the operator refined the plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table): void {
            $table->text('assistant_summary')->nullable();
            $table->json('assistant_strategy')->default('{}');
            $table->unsignedInteger('assistant_version')->default(0);
            $table->unsignedInteger('assistant_accepted_version')->nullable();
            $table->timestamp('assistant_proposed_at')->nullable();
            $table->timestamp('assistant_accepted_at')->nullable();
        });

        Schema::create('content_ideas', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_plan_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('proposal_version');
            $table->string('idea_key');
            $table->string('title');
            $table->string('pillar')->default('');
            $table->text('thesis');
            $table->json('evidence')->default('[]');
            $table->string('goal')->default('');
            $table->string('audience')->default('');
            $table->text('angle')->nullable();
            $table->json('channels');
            $table->date('scheduled_for');

            $table->timestamps();

            $table->unique(
                ['content_plan_id', 'proposal_version', 'idea_key'],
                'content_ideas_plan_version_key_unique',
            );
            $table->index(
                ['project_id', 'content_plan_id', 'proposal_version'],
                'content_ideas_project_plan_version_index',
            );
            $table->index(['project_id', 'scheduled_for']);
        });

        Schema::create('content_plan_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('proposal_version');
            $table->string('role', 16);
            $table->text('body');
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(
                ['project_id', 'content_plan_id', 'created_at'],
                'content_plan_messages_project_plan_created_index',
            );
        });

        Schema::table('content_items', function (Blueprint $table): void {
            // A finished draft survives a discarded idea version, just as an
            // idea already outlives the plan that first scheduled it.
            $table->foreignUlid('content_idea_id')->nullable()
                ->constrained()->nullOnDelete();
            // One expression of an idea per channel. Null idea ids belong to
            // older and non-assistant content and remain unrestricted.
            $table->unique(
                ['content_idea_id', 'channel_type'],
                'content_items_idea_channel_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropUnique('content_items_idea_channel_unique');
            $table->dropConstrainedForeignId('content_idea_id');
        });

        Schema::dropIfExists('content_plan_messages');
        Schema::dropIfExists('content_ideas');

        Schema::table('content_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'assistant_summary',
                'assistant_strategy',
                'assistant_version',
                'assistant_accepted_version',
                'assistant_proposed_at',
                'assistant_accepted_at',
            ]);
        });
    }
};
