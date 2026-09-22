<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\ContentItemType;
use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;

/** Reject old queued social work at the execution boundary, preserving its history. */
final class RetiredSocialDelivery
{
    public static function applies(WebhookDelivery $delivery): bool
    {
        if (config('social.enabled')) {
            return false;
        }

        $delivery->loadMissing(['channel', 'contentItem']);

        return $delivery->channel->type->isSocial()
            || $delivery->contentItem?->isSocial() === true
            || data_get($delivery->payload_snapshot, 'content.type') === ContentItemType::SocialPost->value;
    }

    public static function stop(WebhookDelivery $delivery): bool
    {
        if (! self::applies($delivery)) {
            return false;
        }

        WebhookDelivery::query()->whereKey($delivery->getKey())
            ->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::Retrying])
            ->update([
                'status' => DeliveryStatus::DeadLetter,
                'next_attempt_at' => null,
                'error' => 'Social publishing is retired. No further delivery attempts will be made.'
                    .($delivery->error === null ? '' : ' Previous outcome: '.$delivery->error),
            ]);
        $delivery->refresh();

        return true;
    }
}
