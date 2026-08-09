<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/** Google did not answer, or answered with something transient. Retryable. */
class GoogleUnavailable extends RuntimeException {}
