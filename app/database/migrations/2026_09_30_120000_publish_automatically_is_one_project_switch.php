<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic publishing becomes one switch, on the project.
 *
 * Until now three things had to agree before an article went out unattended:
 * the project's "publish automatically", the channel's "publish scheduled
 * articles automatically" checkbox, and the schedule's own mode. From here on
 * only the project's answer counts, and an owner can hold a single article for
 * review (`article_schedules.held_for_review`). `channels.autopublish` stays
 * in the table, read by nothing.
 *
 * Dropping a switch must not start publishing anything that was not
 * publishing before, so the data is carried over by what it *did*:
 *
 * 1. Projects that said "automatic" but could never publish that way become
 *    review-first: every usable website had its checkbox off, or the topic is
 *    one where every automatic approval is refused (YMYL). A project with no
 *    usable website yet keeps its answer — nothing has published, and the
 *    first passing test would have switched automatic publishing on for it.
 * 2. Holds are recorded where the owner asked for review on an article of an
 *    automatic project, and where an article of a project that stays
 *    automatic was aimed at a website whose checkbox was off — those were
 *    waiting, and would otherwise go.
 * 3. Every schedule nothing has been sent for takes the mode that follows
 *    from 1 and 2, and loses the blocks the old switches put on it.
 *
 * Plain queries throughout: the application's rules will move on, and this
 * has to keep meaning what it meant on the day it ran.
 */
return new class extends Migration
{
    /** What the old code wrote on a schedule stopped only by a switch. */
    private const array SWITCH_REASONS = [
        'Enable automatic publishing for this website or choose review first.',
        'Automatic publishing is turned off for this project.',
    ];

    /** What it wrote on an engine schedule with no website to go to. */
    private const array NO_WEBSITE_REASONS = [
        'Choose a verified website with automatic publishing enabled.',
        'Choose a verified website for this review-first schedule.',
    ];

    public function up(): void
    {
        Schema::table('article_schedules', function (Blueprint $table): void {
            $table->boolean('held_for_review')->default(false);
        });

        DB::table('projects')->orderBy('id')->each(function (stdClass $project): void {
            $this->carryOver($project);
        });
    }

    public function down(): void
    {
        // Only the column. Projects moved to review-first stay there: putting
        // them back would switch on publishing nobody has seen for a week.
        Schema::table('article_schedules', function (Blueprint $table): void {
            $table->dropColumn('held_for_review');
        });
    }

    private function carryOver(stdClass $project): void
    {
        $channels = DB::table('channels')->where('project_id', $project->id)->get()
            ->filter(fn (stdClass $channel): bool => $this->usable($channel));
        $wasAutomatic = (bool) $project->autopublish;
        $automatic = $wasAutomatic && ! $project->is_ymyl
            && ($channels->isEmpty() || $channels->contains(fn (stdClass $channel): bool => (bool) $channel->autopublish));

        if ($automatic !== $wasAutomatic) {
            DB::table('projects')->where('id', $project->id)->update(['autopublish' => false, 'updated_at' => now()]);
        }

        $waiting = fn () => DB::table('article_schedules')->where('project_id', $project->id)
            ->whereNull('delivery_id')->whereNotIn('status', ['completed', 'dispatching']);

        if ($wasAutomatic) {
            $waiting()->where('origin', 'manager')->where('mode', 'review_first')->update(['held_for_review' => true]);
        }

        if ($automatic) {
            $switchedOff = DB::table('channels')->where('project_id', $project->id)->where('autopublish', false)->pluck('id');
            $waiting()->where('mode', 'automatic')->whereIn('channel_id', $switchedOff)->update(['held_for_review' => true]);
        }

        $mode = $automatic ? 'automatic' : 'review_first';

        // Blocks first, while the mode still shows which rows are changing. A
        // reason that is still true after this is written again the next time
        // the schedule is due; the ones cleared here only ever came from a
        // switch that no longer exists.
        $waiting()->where('status', 'blocked')->whereNotNull('channel_id')
            ->where(fn ($query) => $query->whereIn('blocked_reason', self::SWITCH_REASONS)
                ->orWhere(fn ($drifted) => $drifted->where('held_for_review', false)->where('mode', '!=', $mode))
                ->orWhere(fn ($held) => $held->where('held_for_review', true)->where('mode', 'automatic')))
            ->update(['status' => 'active', 'blocked_reason' => null]);
        $waiting()->where('status', 'blocked')->whereNull('channel_id')->whereIn('blocked_reason', self::NO_WEBSITE_REASONS)
            ->update(['blocked_reason' => $channels->isEmpty()
                ? 'Connect and test your website so this article can publish.'
                : 'Choose which website this article should publish to.']);

        $waiting()->where('held_for_review', true)->where('mode', 'automatic')
            ->update(['mode' => 'review_first', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        $waiting()->where('held_for_review', false)->where('mode', '!=', $mode)
            ->update(['mode' => $mode, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    /** `ArticleSchedules::compatible()` as it reads today. */
    private function usable(stdClass $channel): bool
    {
        $config = is_string($channel->config) ? (json_decode($channel->config, true) ?: []) : [];

        return (bool) $channel->is_enabled && $channel->verified_at !== null && $channel->secret !== null
            && match ($channel->type) {
                'webhook' => trim((string) ($config['endpoint'] ?? '')) !== '',
                'wordpress' => ($config['article_publishing_verified'] ?? false) === true,
                default => false,
            };
    }
};
