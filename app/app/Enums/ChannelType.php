<?php

declare(strict_types=1);

namespace App\Enums;

use App\Publishing\ChannelPublisherRegistry;

/**
 * Where a project publishes.
 *
 * Webhook and WordPress channels are pushed to by the publishers registered in
 * {@see ChannelPublisherRegistry}. The pull API has none, because its readers
 * come to the engine instead. The enum is what the `type` column is validated
 * against.
 */
enum ChannelType: string
{
    /** The engine POSTs to a receiver the project owns. The phase 6 contract. */
    case Webhook = 'webhook';

    case WordPress = 'wordpress';

    /** The project pulls finished units from the engine instead of being pushed to. */
    case PullApi = 'pull_api';

    public function label(): string
    {
        return match ($this) {
            self::Webhook => 'Webhook',
            self::WordPress => 'WordPress',
            self::PullApi => 'Pull API',
        };
    }
}
