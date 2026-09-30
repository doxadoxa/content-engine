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
        if ($item === null) {
            return null;
        }
        $schedule = ArticleSchedule::query()->where('content_item_id', $item->id)->first();
        if ($schedule === null || ($schedule->status === 'completed' && $delivery->article_schedule_id === null)) {
            return null;
        }
        // Handed back to the owner (see below): Try again is not the way out,
        // approving the article is, and it sends this same delivery.
        if ($schedule->delivery_id === $delivery->id && ArticleSchedules::awaitingOwnerAfterAttempt($schedule)) {
            return 'Approve the article first. Avyo sends it as soon as you do.';
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

        // Asked of Avyo's own approvals only. A person who approved the
        // article, or pressed Publish now, has read it and decided; a failed
        // fact check is theirs to overrule — so it goes to them.
        if ($schedule->approved_by_avyo && ($item->factcheck['passed'] ?? false) !== true) {
            return app(ArticleSchedules::class)->awaitOwner($schedule, $item, BlockedCode::FACT_CHECK,
                'The fact check has not passed. Review the article, then approve it to publish.');
        }

        $project = $item->project->fresh();
        $entitlements = app(Entitlements::class);
        $entitlements->forget($project);
        if ($project->status !== ProjectStatus::Active || ! $entitlements->for($project)->mayPublish()) {
            return 'Publishing is paused for this project or its plan.';
        }
        // Avyo approved it, and since then the project went review-first or
        // the owner held the article: nobody has said yes to this one yet.
        if ($schedule->approved_by_avyo && (! $project->autopublish || $schedule->mode !== 'automatic')) {
            return app(ArticleSchedules::class)->awaitOwner($schedule, $item, BlockedCode::NEEDS_APPROVAL, ArticleSchedules::NEEDS_APPROVAL);
        }

        return null;
    }

    /**
     * Whether a scheduled article's website cannot take it right now.
     *
     * Not part of {@see refusal()}: a website that failed its last test is a
     * wait, not a verdict on the article. The transport holds the delivery
     * without spending an attempt, and a passing test sends it on.
     */
    public function websiteUnusable(WebhookDelivery $delivery): bool
    {
        $delivery->loadMissing('channel');

        return $delivery->article_schedule_id !== null && ! app(ArticleSchedules::class)->compatible($delivery->channel);
    }
}
