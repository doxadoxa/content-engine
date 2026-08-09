<?php

declare(strict_types=1);

namespace App\Pipelines\Exceptions;

use RuntimeException;

/**
 * "Try me again" — the network was down, the provider throttled us, something
 * timed out. Nothing about the input was wrong (§3.1).
 */
class RetryableStepFailure extends RuntimeException {}
