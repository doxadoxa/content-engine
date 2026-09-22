<?php

declare(strict_types=1);

namespace App\Publishing\Articles;

use App\Billing\Entitlements;
use App\Enums\ProjectStatus;
use App\Models\ArticleSchedule;
use App\Models\WebhookDelivery;

final class ArticleDeliveryGuard
{
    public function refusal(WebhookDelivery $delivery): ?string
    {
        $delivery->loadMissing(['contentItem.project', 'channel']);
        $item = $delivery->contentItem;
        if ($item === null || $item->isSocial()) {
            return null;
        }
        $schedule = ArticleSchedule::query()->where('content_item_id', $item->id)->first();
        if ($schedule === null || ($schedule->status === 'completed' && $delivery->article_schedule_id === null)) {
            return null;
        }
        if ($delivery->article_schedule_id !== $schedule->id
            || $delivery->article_schedule_version !== $schedule->version
            || $schedule->delivery_id !== $delivery->id
            || $schedule->channel_id !== $delivery->channel_id
            || $schedule->status !== 'dispatching'
            || $schedule->publish_at->isFuture()) {
            return 'This delivery no longer matches the active, due publication schedule.';
        }
        if (app(ArticleSchedules::class)->missedAutomaticDate($schedule, $item->project)) {
            return 'This automatic publication date was missed. Choose a new date to keep articles spaced out.';
        }
        $content = $delivery->payload_snapshot['content'] ?? [];
        $expected = ['id' => $item->id, 'locale' => $item->locale, 'slug' => $item->slug,
            'title' => $item->title, 'summary' => (string) $item->summary,
            'markdown' => (string) $item->body_markdown, 'html' => (string) $item->body_html,
            'type' => $item->type->value, 'locale_group_id' => $item->locale_group_id,
            'json_ld' => $item->json_ld, 'faq_json_ld' => $item->faq_json_ld,
            'author' => $item->author, 'internal_links' => $item->internal_links];
        foreach ($expected as $key => $value) {
            if (($content[$key] ?? null) !== $value) {
                return 'The article changed after this delivery was queued. Review the current article before publishing.';
            }
        }

        if ($schedule->mode === 'automatic' && ($item->factcheck['passed'] ?? false) !== true) {
            return 'The fact check has not passed. Review the article before publishing.';
        }

        $project = $item->project->fresh();
        $entitlements = app(Entitlements::class);
        $entitlements->forget($project);
        if ($project->status !== ProjectStatus::Active || ! $entitlements->for($project)->mayPublish()) {
            return 'Publishing is paused for this project or its plan.';
        }
        if (! app(ArticleSchedules::class)->compatible($delivery->channel)
            || ($schedule->origin === 'engine' && $schedule->mode === 'automatic' && ! $project->autopublish)
            || ($schedule->mode === 'automatic' && ! $delivery->channel->autopublish)) {
            return 'The scheduled website connection is no longer enabled for this publication.';
        }

        return null;
    }
}
