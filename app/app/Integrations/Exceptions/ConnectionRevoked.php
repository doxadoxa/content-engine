<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * The grant is gone and no amount of retrying brings it back.
 *
 * Distinct from {@see GoogleUnavailable} because the two need opposite
 * handling: this one marks the connection broken and asks a human to
 * reconnect, the other one waits and tries again.
 */
class ConnectionRevoked extends RuntimeException {}
