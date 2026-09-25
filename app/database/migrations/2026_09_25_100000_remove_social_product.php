<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The social product is gone, and so is what it made. What it cost stays.
 *
 * It was retired behind `social.enabled` first, which kept its rows around as
 * content the article product had to step around: every "is this an article"
 * query excluded posts, every run list filtered out social pipelines, and a
 * delivery replay had to ask whether the delivery was a retired post. Deleting
 * the content is what lets all of that go.
 *
 * Deleted:
 *
 * - Every `social_post`, and every row hanging off a parent (only the
 *   repurpose tree ever set one). Their assets, metrics and schedules cascade.
 * - Article ideas social listening or the coverage-gap planner put in the
 *   pool and no plan ever took: unplanned `idea` rows carrying a `signal_id`
 *   or a `coverage_gap`. Once those columns go they would be indistinguishable
 *   from researched ideas, and the engine would draft them.
 * - Deliveries of posts, whether they still point at one or only say so in
 *   their snapshot (`content.type`): deleting a post nulls a delivery's
 *   `content_item_id`, which would leave a delivery about nothing.
 * - The files behind the deleted assets, once the transaction has committed.
 *   Best effort: a file that is already gone, or a disk that no longer
 *   exists, is logged and skipped, and a file a kept asset still points at is
 *   left alone.
 * - The social tables, then the columns that pointed at them or only served
 *   them. Dropping a column drops the indexes and constraints built on it.
 * - Social channels (their deliveries cascade), the Threads grants, the
 *   `social_posts` counters and overrides.
 *
 * Kept: the pipeline runs of the retired pipelines (`social_*`,
 * `content_studio`, `repurpose`) and of any other run about a deleted item,
 * with their steps and provider spend records. Step costs are what
 * `ProjectSpend` adds up for the cost ceiling, delivery economics and the
 * admin spend screens, so deleting the runs would quietly refund a month of
 * real spend. Runs already settled stay exactly as
 * they were; a run about a deleted item simply loses its `content_item_id`
 * (the foreign key nulls it). A run that could still move — pending or
 * running — is cancelled first, with its totals rolled up from its steps as
 * settling would have done, so no worker, reaper or tick can pick it back up
 * for a pipeline this build no longer has. The runner checks a run's status
 * before it asks the registry for the pipeline, and nothing else asks the
 * registry about a run that already exists.
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
        $items = <<<'SQL'
            select id from content_items
            where type = 'social_post'
               or parent_id is not null
               or (
                   state = 'idea'
                   and content_plan_id is null
                   and (signal_id is not null or coverage_gap is not null)
               )
            SQL;

        $runs = <<<SQL
            select id from pipeline_runs
            where pipeline like 'social\_%'
               or pipeline in ('content_studio', 'repurpose')
               or coalesce(content_item_id, input->>'content_item_id') in ({$items})
            SQL;

        // Cancelled rather than deleted: the steps carry the cost. The totals
        // are what PipelineRun::rollUpTotals() would have written on settling.
        DB::statement(<<<SQL
            update pipeline_runs
            set status = 'cancelled',
                finished_at = now(),
                updated_at = now(),
                error = '{"message":"The social product was removed, so this run was stopped."}'::json,
                input_tokens = coalesce((select sum(s.input_tokens) from pipeline_steps s where s.pipeline_run_id = pipeline_runs.id), 0),
                output_tokens = coalesce((select sum(s.output_tokens) from pipeline_steps s where s.pipeline_run_id = pipeline_runs.id), 0),
                cost_micros = coalesce((select sum(s.cost_micros) from pipeline_steps s where s.pipeline_run_id = pipeline_runs.id), 0)
            where status in ('pending', 'running')
              and id in ({$runs})
            SQL);

        // Before the rows that say where they are go.
        $files = DB::table('assets')
            ->whereRaw("content_item_id in ({$items})")
            ->orWhere('source', 'rendered')
            ->distinct()
            ->get(['disk', 'path']);

        DB::statement(<<<SQL
            delete from webhook_deliveries
            where content_item_id in ({$items})
               or payload_snapshot->'content'->>'type' = 'social_post'
            SQL);

        // Children before parents: the parent key is NO ACTION. Runs about
        // these rows keep their history with `content_item_id` nulled.
        DB::table('content_items')->whereNotNull('parent_id')->delete();
        DB::statement("delete from content_items where id in ({$items})");

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

        DB::afterCommit(fn () => $this->deleteFiles($files));
    }

    /**
     * After the commit, so a migration that fails later cannot leave rows
     * pointing at files it already deleted. Never fatal: the rows are gone
     * either way, and a stray file costs less than a half-run deploy.
     *
     * @param  Collection<int, stdClass>  $files
     */
    private function deleteFiles(Collection $files): void
    {
        foreach ($files as $file) {
            // Rows share files: HeroImage lends one locale's pictures to its
            // siblings by path. A file a kept row still names stays.
            $disk = (string) $file->disk;
            $path = (string) $file->path;

            if (DB::table('assets')->where('disk', $disk)->where('path', $path)->exists()) {
                continue;
            }

            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable $e) {
                Log::warning('Could not delete a social media file', [
                    'disk' => $disk,
                    'path' => $path,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
};
