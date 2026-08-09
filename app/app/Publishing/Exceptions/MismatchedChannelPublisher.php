<?php

declare(strict_types=1);

namespace App\Publishing\Exceptions;

use App\Enums\ChannelType;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\Contracts\ChannelPublisher;
use LogicException;

/**
 * A transport registered against a type it does not claim.
 *
 * {@see ChannelPublisherRegistry} indexes on the type it is handed and never
 * used to consult {@see ChannelPublisher::supports()}, so the two could
 * disagree and nothing would say so: a line in `AppServiceProvider` with the
 * wrong constant would route every Threads delivery through the webhook
 * transport, which would sign a post with a shared secret and POST it at an
 * endpoint the channel does not have. The failure would surface as a delivery
 * error about a missing URL, three layers away from the wrong word.
 *
 * A `LogicException` rather than a runtime one on purpose. This cannot be
 * caused by data, a network, or an operator — only by a line of wiring, and the
 * only correct response is to change it.
 */
class MismatchedChannelPublisher extends LogicException
{
    /**
     * @param  class-string<ChannelPublisher>  $publisher
     */
    public static function for(ChannelType $type, string $publisher): self
    {
        return new self(
            "{$publisher} is registered for the '{$type->value}' channel type but does not support it."
        );
    }
}
