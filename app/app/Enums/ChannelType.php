<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a project publishes.
 *
 * Phase 2 only records the configuration; the adapters that act on it arrive in
 * phase 6 (webhook) and phase 8 (the rest). The cases are all listed now
 * because the enum is what the `type` column is validated against, and adding a
 * case later is a migration on every row that already stored a string.
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
