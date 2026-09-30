<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic publishing becomes one switch, on the project.
 *
 * Until now three things had to agree before an article went out unattended:
 * the project's "publish automatically", the website's "publish scheduled
 * articles automatically" checkbox, and the schedule's own mode. From here on
 * only the project's answer counts, and an owner can hold a single article for
 * review. Three columns go on `article_schedules`:
 *
 * - `held_for_review`: the owner's hold on one article;
 * - `approved_by_avyo`: whether the article's approval was Avyo's own, which
 *   stops counting the moment its schedule has to wait for a person;
 * - `blocked_code`: why a schedule is blocked, for code to read instead of
 *   the sentence.
 *
 * `channels.autopublish` stays in the table, read by nothing.
 *
 * Dropping a switch must not start publishing anything that was not
 * publishing before, so the data is carried over by what it *did*, and where
 * that is unknown, by the reading that publishes less:
 *
 * 1. A project that said "automatic" but could never publish that way becomes
 *    review-first: every website that could carry articles, or that its owner
 *    had switched off (`autopublish_declined_at`, even if an edit has made it
 *    unusable since), had the checkbox off — or the topic is one where every
 *    automatic approval is refused (YMYL). A project with no such website yet
 *    keeps its answer: nothing has published, and the first passing test
 *    would have switched automatic publishing on for it.
 * 2. On a project that was automatic, every article already waiting for
 *    review keeps waiting (held), whoever scheduled it. So does an automatic
 *    article aimed at a website whose checkbox was off.
 * 3. A delivery still queued for such a website, or queued automatically on a
 *    project that no longer allowed it, would have been refused when sent. It
 *    is withdrawn and the article held — unless its attempt has started, which
 *    must keep its identity; that article is only held.
 * 4. Every schedule nothing has been sent for takes the mode that follows, and
 *    loses the blocks the old switches put on it — except where its date has
 *    already passed, which waits for a new date rather than going out in a
 *    burst on deploy day.
 * 5. An approved article whose schedule now waits for review goes back to
 *    waiting for the owner: who approved it was never recorded, so it is taken
 *    to be Avyo. Its allowance record stays; approving it again is not charged.
 *    Approved articles on automatic schedules are likewise counted as Avyo's,
 *    so the fact check still applies to them when they are sent.
 *
 * Plain queries throughout: the application's rules will move on, and this
 * has to keep meaning what it meant on the day it ran.
 */
return new class extends Migration
{
    private const string MISSED_DATE = 'This automatic publication date was missed. Choose a new date to keep articles spaced out.';

    private const string NEEDS_APPROVAL = 'Review and approve this article before it can publish.';

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
            $table->boolean('approved_by_avyo')->default(false);
            $table->string('blocked_code')->nullable();
        });

        DB::table('projects')->orderBy('id')->each(function (stdClass $project): void {
            $this->carryOver($project);
        });
    }

    public function down(): void
    {
        // Only the columns. Projects moved to review-first stay there, and
        // articles sent back for review stay drafts: undoing either would
        // start publishing things nobody has looked at for a week.
        Schema::table('article_schedules', function (Blueprint $table): void {
            $table->dropColumn(['held_for_review', 'approved_by_avyo', 'blocked_code']);
        });
    }

    private function carryOver(stdClass $project): void
    {
        $channels = DB::table('channels')->where('project_id', $project->id)->get()->keyBy('id');
        $usable = $channels->filter(fn (stdClass $channel): bool => $this->usable($channel));
        $declined = $channels->filter(fn (stdClass $channel): bool => ($channel->autopublish_declined_at ?? null) !== null);
        $wasAutomatic = (bool) $project->autopublish;
        $automatic = $wasAutomatic && ! $project->is_ymyl
            && (($usable->isEmpty() && $declined->isEmpty()) || $usable->contains(fn (stdClass $channel): bool => (bool) $channel->autopublish));

        if ($automatic !== $wasAutomatic) {
            DB::table('projects')->where('id', $project->id)->update(['autopublish' => false, 'updated_at' => now()]);
        }

        $switchedOff = fn (?string $channelId): bool => $channelId !== null && isset($channels[$channelId]) && ! $channels[$channelId]->autopublish;
        $schedules = DB::table('article_schedules')->where('project_id', $project->id)->where('status', '!=', 'completed')->get();

        foreach ($schedules as $schedule) {
            $code = $schedule->status === 'blocked' ? $this->code($schedule->blocked_reason, $project) : null;

            if ($schedule->status === 'dispatching') {
                $this->inFlight($schedule, $wasAutomatic, $switchedOff($schedule->channel_id));

                continue;
            }
            if ($schedule->delivery_id !== null) {
                DB::table('article_schedules')->where('id', $schedule->id)->update(['blocked_code' => $code]);

                continue;
            }

            $held = ($wasAutomatic && $schedule->mode === 'review_first')
                || ($automatic && $schedule->mode === 'automatic' && $switchedOff($schedule->channel_id));
            $mode = $held || ! $automatic ? 'review_first' : 'automatic';
            $changes = ['held_for_review' => $held, 'blocked_code' => $code];

            if ($schedule->status === 'blocked' && $schedule->channel_id === null && in_array($schedule->blocked_reason, self::NO_WEBSITE_REASONS, true)) {
                $none = $usable->isEmpty();
                $changes['blocked_reason'] = $none ? 'Connect and test your website so this article can publish.' : 'Choose which website this article should publish to.';
                $changes['blocked_code'] = $none ? 'no_website' : 'choose_website';
            }

            // Only blocks the old switches put there, or that the mode decided.
            // One that is still true is written again the next time it is due.
            if ($schedule->status === 'blocked' && $schedule->channel_id !== null && $code !== 'missed_date'
                && (in_array($schedule->blocked_reason, self::SWITCH_REASONS, true) || $mode !== $schedule->mode)) {
                $changes = [...$changes, ...($this->due($schedule)
                    ? ['blocked_reason' => self::MISSED_DATE, 'blocked_code' => 'missed_date']
                    : ['status' => 'active', 'blocked_reason' => null, 'blocked_code' => null])];
            }

            if ($mode !== $schedule->mode) {
                $changes = [...$changes, 'mode' => $mode, 'version' => $schedule->version + 1, 'updated_at' => now()];
            }
            if ($mode === 'review_first' && $schedule->mode === 'automatic') {
                $this->backToReview($schedule->content_item_id);
            }

            DB::table('article_schedules')->where('id', $schedule->id)->update($changes);
        }

        // Nobody recorded who approved these; the fact check applies to them
        // as it did to every automatic schedule before.
        DB::table('article_schedules')->where('project_id', $project->id)
            ->where('mode', 'automatic')->whereNotIn('status', ['completed', 'canceled'])
            ->whereIn('content_item_id', DB::table('content_items')->where('project_id', $project->id)->where('state', 'approved')->select('id'))
            // An attempt already under way finishes under its own identity.
            ->where(fn ($unsent) => $unsent->whereNull('delivery_id')
                ->orWhereNotIn('delivery_id', DB::table('webhook_deliveries')->whereNotNull('article_attempt_started_at')->select('id')))
            ->update(['approved_by_avyo' => true]);
    }

    /**
     * A delivery queued before today, that the old rules would have refused
     * when it was sent: to a website whose checkbox was off, or automatically
     * for an Avyo-scheduled article on a project that had said review first.
     */
    private function inFlight(stdClass $schedule, bool $wasAutomatic, bool $switchedOff): void
    {
        $refused = $schedule->mode === 'automatic' && ($switchedOff || ($schedule->origin === 'engine' && ! $wasAutomatic));
        $delivery = $schedule->delivery_id === null ? null : DB::table('webhook_deliveries')->where('id', $schedule->delivery_id)->first();

        if (! $refused || $delivery === null || ! in_array($delivery->status, ['pending', 'retrying'], true)) {
            return;
        }

        if ($delivery->article_attempt_started_at !== null) {
            // Possibly already at the website: it finishes under the identity
            // it started with. The version is left alone for that reason.
            DB::table('article_schedules')->where('id', $schedule->id)
                ->update(['held_for_review' => true, 'mode' => 'review_first']);

            return;
        }

        DB::table('webhook_deliveries')->where('id', $delivery->id)->update([
            'status' => 'dead_letter', 'next_attempt_at' => null, 'dispatch_key' => null, 'updated_at' => now(),
            'error' => 'Held for your review before it was sent. Approve the article to publish it.',
        ]);
        DB::table('article_schedules')->where('id', $schedule->id)->update([
            'held_for_review' => true, 'mode' => 'review_first', 'status' => 'blocked', 'delivery_id' => null,
            'blocked_reason' => self::NEEDS_APPROVAL, 'blocked_code' => 'needs_approval',
            'version' => $schedule->version + 1, 'updated_at' => now(),
        ]);
        $this->backToReview($schedule->content_item_id);
    }

    private function backToReview(string $itemId): void
    {
        DB::table('content_items')->where('id', $itemId)->where('state', 'approved')
            ->update(['state' => 'draft', 'updated_at' => now()]);
    }

    private function due(stdClass $schedule): bool
    {
        return now()->greaterThanOrEqualTo(Carbon::parse($schedule->publish_at));
    }

    /** The code for a sentence the application has written on a schedule. */
    private function code(?string $reason, stdClass $project): string
    {
        $reason ??= '';

        return match (true) {
            in_array($reason, self::NO_WEBSITE_REASONS, true), $reason === 'Connect and test your website so this article can publish.' => 'no_website',
            $reason === 'Choose which website this article should publish to.' => 'choose_website',
            str_contains($reason, 'date was missed') => 'missed_date',
            $reason === 'Publishing is paused for this project or its plan.' => $project->status === 'active' ? 'plan' : 'project_paused',
            str_starts_with($reason, 'Automatic publication is paused') => 'project_paused',
            str_starts_with($reason, 'An active plan') => 'plan',
            str_starts_with($reason, 'No articles remain') => 'allowance_used',
            str_contains($reason, 'fact check') => 'fact_check',
            str_starts_with($reason, 'This draft needs attention') => 'score',
            $reason === self::NEEDS_APPROVAL, str_contains($reason, 'needs a person'), $reason === 'This article has no active automatic schedule.',
            in_array($reason, self::SWITCH_REASONS, true) => 'needs_approval',
            str_contains($reason, 'website connection'), str_contains($reason, 'before articles publish automatically'),
            str_contains($reason, 'automatic publishing enabled before automatic approval') => 'website_paused',
            str_starts_with($reason, 'The previous delivery') => 'previous_delivery',
            default => 'other',
        };
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
