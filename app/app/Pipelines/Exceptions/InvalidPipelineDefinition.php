<?php

declare(strict_types=1);

namespace App\Pipelines\Exceptions;

use RuntimeException;

/**
 * The pipeline itself is wrong — a cycle, a dangling dependency, a duplicate
 * step key. Always a programming error, never a runtime condition, which is why
 * it is raised when a run is created rather than when a step is dispatched.
 */
class InvalidPipelineDefinition extends RuntimeException {}
