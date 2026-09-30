<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\DeliveryStatus;
use App\Models\Channel;
use App\Models\WebhookDelivery;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\Jobs\ResumeHeldArticlesJob;
use Illuminate\Support\Facades\Cache;

/**
 * Articles waiting for their website, and sending them on once it can take
 * them.
 *
 * A scheduled article whose website is paused or failed its last test is not
 * a failure: its delivery waits, marked by its `error`
 * ({@see WebhookPublisher::WAITING_FOR_WEBSITE}, `WAITING_FOR_RESUME`),
 * without spending an attempt. Left alone it looks again every few hours;
 * {@see resume()} is what makes it go the moment the owner fixes the website
 * — a test that passes, or pressing Resume.
 */
final class HeldArticles
{
    /** @return list<string> */
    public static function waitingErrors(): array
    {
        return [WebhookPublisher::WAITING_FOR_WEBSITE, WebhookPublisher::WAITING_FOR_RESUME];
    }

    /**
     * The owner resumed the website: what waited for the pause now waits for
     * the website to work, which is what it will say until a test passes.
     */
    public function unpaused(Channel $channel): void
    {
        WebhookDelivery::acrossProjects()->where('channel_id', $channel->getKey())
            ->where('status', DeliveryStatus::Retrying->value)->where('error', WebhookPublisher::WAITING_FOR_RESUME)
            ->update(['error' => WebhookPublisher::WAITING_FOR_WEBSITE]);
    }

    /**
     * Make every article waiting for this website due now.
     *
     * Each gets a job carrying that time, so the job it already had finds
     * the rung taken and stands down. A row an attempt holds right now may be
     * deciding to wait on what the website was a moment ago, so it is looked
     * at again once that attempt's lock has run out.
     */
    public function resume(Channel $channel): void
    {
        $held = WebhookDelivery::acrossProjects()->where('channel_id', $channel->getKey())
            ->whereNotNull('article_schedule_id')->where('status', DeliveryStatus::Retrying->value)
            ->whereIn('error', self::waitingErrors())->pluck('id')
            ->map(fn (mixed $id): string => (string) $id);
        $busy = false;

        foreach ($held as $id) {
            $lock = Cache::lock('webhook-delivery:'.$id, WebhookPublisher::lockSeconds());
            if (! $lock->get()) {
                $busy = true;

                continue;
            }
            try {
                $delivery = WebhookDelivery::acrossProjects()->whereKey($id)->first();
                if ($delivery === null || $delivery->status !== DeliveryStatus::Retrying) {
                    continue;
                }
                $delivery->forceFill(['next_attempt_at' => now()])->save();
                $due = $delivery->refresh()->next_attempt_at;
            } finally {
                $lock->release();
            }
            DeliverWebhookJob::dispatch($id, $due)->afterCommit();
        }

        if ($busy) {
            ResumeHeldArticlesJob::dispatch((string) $channel->getKey())
                ->afterCommit()->delay(now()->addSeconds(WebhookPublisher::lockSeconds() + 5));
        }
    }
}
