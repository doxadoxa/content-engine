<?php

declare(strict_types=1);

namespace App\Pipelines\Exceptions;

use RuntimeException;

/**
 * "Do not try me again" — the input failed validation, the model refused, the
 * step was asked for something impossible (§3.1).
 *
 * Retrying this spends money to reach the same answer more slowly.
 */
class TerminalStepFailure extends RuntimeException {}
