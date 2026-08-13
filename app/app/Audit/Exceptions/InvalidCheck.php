<?php

declare(strict_types=1);

namespace App\Audit\Exceptions;

use RuntimeException;

/** A check registered in config/audit.php that this build cannot use. */
class InvalidCheck extends RuntimeException {}
