<?php

declare(strict_types=1);

namespace App\Publishing\Articles;

use App\Billing\Entitlements;
use App\Enums\ProjectStatus;
use App\Models\ArticleSchedule;
use App\Models\ContentItem;
use App\Models\WebhookDelivery;

final class ArticleDeliveryGuard
{
    public function refusal(WebhookDelivery $delivery): ?string
    {
        return $this->verdict($delivery)?->reason;
    }

    /**
     * Why this delivery may not go out now, or null when it may.
     *
     * Only reads. A refusal about approval says so ({@see DeliveryRefusal::handsBack()}),
     * and the caller hands the article back to the owner in the same
     * transaction that dead-letters the delivery. Handing back first left a
     * moment when an Approve could relink a delivery that was still retrying.
     */
    public function verdict(WebhookDelivery $delivery): ?DeliveryRefusal
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
            return new DeliveryRefusal('Approve the article first. Avyo sends it as soon as you do.');
        }
        if ($delivery->article_schedule_id !== $schedule->id
            || $delivery->article_schedule_version !== $schedule->version
            || $schedule->delivery_id !== $delivery->id
            || $schedule->channel_id !== $delivery->channel_id
            || $schedule->status !== 'dispatching'
            || $schedule->publish_at->isFuture()) {
            return new DeliveryRefusal('This delivery no longer matches the active, due publication schedule.');
        }
        if (app(ArticleSchedules::class)->missedAutomaticDate($schedule, $item->project)) {
            return new DeliveryRefusal('This automatic publication date was missed. Choose a new date to keep articles spaced out.');
        }
        if (! $this->snapshotMatches($delivery, $item)) {
            return new DeliveryRefusal('The article changed after this delivery was queued. Review the current article before publishing.');
        }

        // Asked of Avyo's own approvals only. A person who approved the
        // article, or pressed Publish now, has read it and decided; a failed
        // fact check is theirs to overrule — so it goes to them.
        if ($schedule->approved_by_avyo && ($item->factcheck['passed'] ?? false) !== true) {
            return new DeliveryRefusal('The fact check has not passed. Review the article, then approve it to publish.',
                BlockedCode::FACT_CHECK, $schedule, $item);
        }

        $project = $item->project->fresh();
        $entitlements = app(Entitlements::class);
        $entitlements->forget($project);
        if ($project->status !== ProjectStatus::Active || ! $entitlements->for($project)->mayPublish()) {
            return new DeliveryRefusal('Publishing is paused for this project or its plan.');
        }
        // Avyo approved it, and since then the project went review-first or
        // the owner held the article: nobody has said yes to this one yet.
        if ($schedule->approved_by_avyo && (! $project->autopublish || $schedule->mode !== 'automatic')) {
            return new DeliveryRefusal(ArticleSchedules::NEEDS_APPROVAL, BlockedCode::NEEDS_APPROVAL, $schedule, $item);
        }

        return null;
    }

    /**
     * Whether the bytes a delivery carries are the article as it is now.
     *
     * The receiver keeps what it is sent, so an article edited after it was
     * queued must not go out in its old words.
     */
    public function snapshotMatches(WebhookDelivery $delivery, ContentItem $item): bool
    {
        $content = $delivery->payload_snapshot['content'] ?? [];
        $expected = ['id' => $item->id, 'locale' => $item->locale, 'slug' => $item->slug,
            'title' => $item->title, 'summary' => (string) $item->summary,
            'markdown' => (string) $item->body_markdown, 'html' => (string) $item->body_html,
            'type' => $item->type->value, 'locale_group_id' => $item->locale_group_id,
            'json_ld' => $item->json_ld, 'faq_json_ld' => $item->faq_json_ld,
            'author' => $item->author, 'internal_links' => $item->internal_links];
        foreach ($expected as $key => $value) {
            if (($content[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a scheduled article's website cannot take it right now.
     *
     * Not part of {@see verdict()}: a website that is paused or failed its
     * last test is a wait, not a verdict on the article. The transport holds
     * the delivery without spending an attempt, and resuming the website, or
     * a passing test, sends it on.
     */
    public function websiteUnusable(WebhookDelivery $delivery): bool
    {
        $delivery->loadMissing('channel');

        return $delivery->article_schedule_id !== null && ! app(ArticleSchedules::class)->compatible($delivery->channel->refresh());
    }
}
