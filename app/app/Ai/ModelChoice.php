<?php

declare(strict_types=1);

namespace App\Ai;

/** Which provider and model a role resolves to right now. */
final readonly class ModelChoice
{
    public function __construct(
        public string $role,
        public string $provider,
        public string $model,
    ) {}
}
