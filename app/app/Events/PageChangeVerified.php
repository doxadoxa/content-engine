<?php

declare(strict_types=1);

namespace App\Events;

final readonly class PageChangeVerified
{
    public function __construct(public string $projectId, public string $publicationId) {}
}
