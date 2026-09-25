<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Publishing\Articles\ArticleSchedules;
use Illuminate\Database\Eloquent\Builder;

/** Shared filters keep dashboard counts and their content links consistent. */
final class ManagerContent
{
    /** @return array<string, mixed> */
    public static function workflow(Project $project): array
    {
        $opted = is_string($project->onboarding['article_automation_started_at'] ?? null);
        $channels = app(ArticleSchedules::class)->channels($project);
        $eligible = $project->autopublish ? $channels->where('autopublish', true) : $channels;
        $ready = $opted && $eligible->count() === 1 && $project->status->value === 'active';
        // Somebody who chose "decide later" in setup made a decision, and it
        // was one we offered. Telling them their publishing setup is missing
        // is nagging them for an answer they gave.
        $later = ($project->onboarding['channels']['destination'] ?? null) === 'later';
        $message = match (true) {
            ! $opted => 'Choose how new articles should publish to start using your calendar automatically.',
            $project->status->value !== 'active' => 'Content work is paused. Resume the business when you are ready.',
            $channels->isEmpty() && $later => 'Articles are waiting in your calendar. Connect your website whenever you are ready, or copy each one across yourself.',
            $channels->isEmpty() => 'Connect and test your website so scheduled articles can go live.',
            $eligible->isEmpty() => 'Enable automatic publishing for your website, or choose review first.',
            $eligible->count() > 1 => 'You have more than one eligible website. Choose the destination on each article’s schedule.',
            $project->autopublish => 'Avyo checks articles before publishing. You can review first or pause individual articles in Calendar.',
            default => 'New articles get a calendar date and wait for your approval before publishing.',
        };

        return ['mode' => $project->autopublish ? 'automatic' : 'review_first', 'opted_in' => $opted,
            'ready' => $ready, 'timezone' => $project->timezone, 'message' => $message,
            'action' => ! $opted || $project->status->value !== 'active' ? '/projects/'.$project->id.'/edit#article-publishing' : ($eligible->count() === 1 ? '/calendar' : '/channels'),
            'action_label' => ! $opted ? 'Choose publishing preference' : ($project->status->value !== 'active' ? 'Resume content work' : ($ready ? 'Manage schedule' : ($later && $channels->isEmpty() ? 'Connect my website' : 'Set up website publishing')))];
    }

    /** @return Builder<ContentItem> */
    public static function query(string $view = 'all'): Builder
    {
        $query = ContentItem::query();

        return match ($view) {
            'writing' => $query->where(fn (Builder $items) => $items->whereIn('state', ['queued', 'generating'])
                ->orWhereIn('id', PipelineRun::query()->inFlight()->whereIn('pipeline', ['generation', 'refresh'])->whereNotNull('content_item_id')->select('content_item_id'))),
            'review' => $query->whereNotIn('state', ['published', 'refreshing'])->where(fn (Builder $items) => $items
                ->where(fn (Builder $drafts) => $drafts->where('state', 'draft')->where(fn (Builder $review) => $review->whereDoesntHave('articleSchedule')
                    ->orWhereHas('articleSchedule', fn (Builder $schedule) => $schedule->where('mode', 'review_first')->whereNotIn('status', ['paused', 'canceled', 'completed']))
                    ->orWhere(fn (Builder $unchecked) => $unchecked->where(fn (Builder $check) => $check->whereNull('factcheck')->orWhereJsonDoesntContain('factcheck', ['passed' => true]))->whereHas('articleSchedule', fn (Builder $schedule) => $schedule->where('status', 'active')))
                    ->orWhere(fn (Builder $sensitive) => $sensitive->whereHas('project', fn (Builder $project) => $project->where('is_ymyl', true))->whereHas('articleSchedule', fn (Builder $schedule) => $schedule->where('status', 'active')))))
                ->orWhereHas('articleSchedule', fn (Builder $schedule) => $schedule->where('status', 'blocked')->orWhere(fn (Builder $failed) => $failed->where('status', 'dispatching')->whereHas('delivery', fn (Builder $delivery) => $delivery->where('status', 'dead_letter'))))),
            'scheduled' => $query->whereNotIn('state', ['published', 'refreshing'])->whereHas('articleSchedule', fn (Builder $schedule) => $schedule->where('status', 'active')->orWhere(fn (Builder $sending) => $sending->where('status', 'dispatching')->whereDoesntHave('delivery', fn (Builder $delivery) => $delivery->where('status', 'dead_letter')))),
            'published' => $query->whereIn('state', ['published', 'refreshing']),
            default => $query,
        };
    }
}
