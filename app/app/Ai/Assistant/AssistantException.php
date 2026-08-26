<?php

declare(strict_types=1);

namespace App\Ai\Assistant;

use RuntimeException;

/** A turn that could not be taken, in words a person can be shown. */
final class AssistantException extends RuntimeException {}
