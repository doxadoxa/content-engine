<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when a model is written with a project_id other than the current one.
 *
 * Always a bug rather than a user error: something built a record for tenant A
 * while tenant B was current, which means the surrounding code is reading one
 * tenant's data and writing another's.
 */
final class CrossTenantWriteException extends RuntimeException
{
    public static function for(string $model, string $attempted, string $current): self
    {
        return new self(
            "Refusing to write {$model} for project [{$attempted}] while project [{$current}] is current."
        );
    }
}
