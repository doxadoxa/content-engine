<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when a tenant-owned model is created with no project to own it.
 *
 * The alternative — writing the row with a null project_id — produces data no
 * query can ever find again, because every read is scoped.
 */
final class TenantMissingException extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(
            "Cannot create {$model}: no current project and no project_id given."
        );
    }
}
