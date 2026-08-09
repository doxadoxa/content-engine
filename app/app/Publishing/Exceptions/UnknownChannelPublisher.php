<?php

declare(strict_types=1);

namespace App\Publishing\Exceptions;

use App\Enums\ChannelType;
use RuntimeException;

/**
 * A channel was handed to the publishing side that no transport claims (§9).
 *
 * Loud on purpose. `ChannelType` lists every destination the `type` column may
 * hold, including the ones with no adapter yet — WordPress, LinkedIn, the pull
 * API — so "unregistered" is a normal state for a row and a silent `null` here
 * would read as "delivered nothing, successfully". That is exactly the failure
 * phase 6 spent a release removing from the panel: a unit that said Published
 * with no reader able to reach it. A registry that throws turns the same
 * mistake into a stack trace with the type's name in it.
 */
class UnknownChannelPublisher extends RuntimeException
{
    public static function for(ChannelType $type): self
    {
        return new self("No publisher is registered for the {$type->value} channel type.");
    }
}
