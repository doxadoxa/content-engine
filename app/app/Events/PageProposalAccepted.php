<?php

declare(strict_types=1);

namespace App\Events;

final readonly class PageProposalAccepted
{
    public function __construct(public string $projectId, public string $proposalId, public string $revisionId) {}
}
