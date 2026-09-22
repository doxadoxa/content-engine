<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PageProposalAccepted;
use App\Feedback\Followups\PinChangeBaseline;

final class PinAcceptedPageBaseline
{
    public function __construct(private readonly PinChangeBaseline $baselines) {}

    public function handle(PageProposalAccepted $event): void
    {
        $this->baselines->capture($event->projectId, $event->proposalId, $event->revisionId);
    }
}
