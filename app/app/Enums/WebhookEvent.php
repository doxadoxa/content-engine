<?php

declare(strict_types=1);

namespace App\Enums;

/** The four events of the publish contract (§5, §6.1). */
enum WebhookEvent: string
{
    case Published = 'content.published';

    /** Without this one the refresh loop of phase 9 cannot exist (§1.3). */
    case Updated = 'content.updated';

    case Deleted = 'content.deleted';

    /** Connection and signature check at setup. Carries no content. */
    case Ping = 'ping';

    public function carriesContent(): bool
    {
        return $this !== self::Ping;
    }

    public function carriesBody(): bool
    {
        return $this === self::Published || $this === self::Updated;
    }
}
