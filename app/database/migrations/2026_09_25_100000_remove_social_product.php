<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The social product is gone, and so is everything it wrote.
 *
 * It was retired behind `social.enabled` first, which kept its rows around as
 * history the article product had to step around: every "is this an article"
 * query excluded posts, every run list filtered out social pipelines, and a
 * delivery replay had to ask whether the delivery was a retired post. Deleting
 * the data is what lets all of that go, so the data goes with the code.
 *
 * In order, because the foreign keys decide it:
 *
 * 1. The runs of the retired pipelines, and any run about a post. Spend
 *    records restrict a run's deletion, so theirs go first; steps cascade.
 * 2. Deliveries of posts. Deleting a post would only null their
 *    `content_item_id`, leaving a delivery about nothing.
 * 3. The posts: every `social_post`, and every row hanging off a parent — only
 *    the repurpose tree ever set one. Children first, because the parent key
 *    is NO ACTION. Assets, metrics and schedules cascade.
 * 4. The social tables, then the columns that pointed at them or only served
 *    them. Dropping a column drops the indexes and constraints built on it.
 * 5. Social channels (their deliveries cascade), the Threads grants, the
 *    `social_posts` counters and overrides.
 *
 * Postgres-only, like the rest of this schema, and in the one transaction
 * Postgres gives every migration: a failure part way leaves nothing deleted.
 * (`??` below is PDO's escape for the jsonb `?` operator.)
 */
return new class extends Migration
{
    private const array CHANNEL_TYPES = ['linkedin', 'x', 'instagram', 'telegram', 'threads'];

    public function up(): void
    {
        $this->deleteSocialData();

        foreach ([
            'interaction_reply_attempts',
            'interactions',
            'social_plans',
            'content_plan_messages',
            'project_states',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn([
                'parent_id',
                'channel_type',
                'planned_derivatives',
                'channel_payload',
                'signal_id',
                'social_band',
                'expires_at',
                'slot_at',
                'coverage_gap',
                'content_idea_id',
            ]);
        });

        // After the columns that referenced them.
        Schema::dropIfExists('content_ideas');
        Schema::dropIfExists('content_goals');
        Schema::dropIfExists('signals');

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

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['duty_hours', 'feed_urls']);
        });

        Schema::table('brand_briefs', function (Blueprint $table): void {
            $table->dropColumn('carousel_cover');
        });
    }

    /**
     * Not reversible. The rows this deleted are gone, and putting back empty
     * tables and columns for code that no longer exists would restore nothing
     * anybody could use.
     */
    public function down(): void
    {
        throw new RuntimeException('Removing the social product deleted its data and cannot be rolled back.');
    }

    private function deleteSocialData(): void
    {
        $posts = <<<'SQL'
            select id from content_items where type = 'social_post' or parent_id is not null
            SQL;

        $runs = <<<SQL
            select id from pipeline_runs
            where pipeline like 'social\_%'
               or pipeline in ('content_studio', 'repurpose')
               or coalesce(content_item_id, input->>'content_item_id') in ({$posts})
            SQL;

        DB::statement("delete from provider_spend_records where pipeline_run_id in ({$runs})");
        DB::statement("delete from pipeline_runs where id in ({$runs})");

        DB::statement("delete from webhook_deliveries where content_item_id in ({$posts})");

        // Children before parents: the parent key is NO ACTION.
        DB::table('content_items')->whereNotNull('parent_id')->delete();
        DB::table('content_items')->where('type', 'social_post')->delete();

        // Panels were only ever drawn for posts, so the cascade above took
        // them; this is for a panel whose post was already gone.
        DB::table('assets')->where('source', 'rendered')->delete();

        DB::table('channels')->whereIn('type', self::CHANNEL_TYPES)->delete();
        DB::table('project_integrations')->where('provider', 'threads')->delete();
        DB::table('project_usage_periods')->where('metric', 'social_posts')->delete();

        DB::statement(<<<'SQL'
            update project_subscriptions
            set limit_overrides = (limit_overrides::jsonb - 'social_posts')::json
            where limit_overrides is not null
              and limit_overrides::jsonb ?? 'social_posts'
            SQL);
    }
};
