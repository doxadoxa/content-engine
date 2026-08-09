<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * The token is finished, and only a human reconnecting fixes it.
 *
 * The sibling of {@see ConnectionRevoked} on the Google side, and separate from
 * {@see ThreadsRefused} for the same reason that one is separate from
 * {@see GoogleUnavailable}: this one marks the integration broken so the
 * settings screen can ask for a reconnection, where a plain refusal leaves the
 * connection alone and blames the request.
 *
 * §9 puts the token on `ProjectIntegration` precisely so there is somewhere for
 * that broken state to live.
 */
class ThreadsCredentialRejected extends RuntimeException {}
