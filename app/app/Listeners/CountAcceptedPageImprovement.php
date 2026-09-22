<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Billing\ImprovementAllowance;
use App\Events\PageProposalAccepted;

final class CountAcceptedPageImprovement
{
    public function __construct(private readonly ImprovementAllowance $allowance) {}

    public function handle(PageProposalAccepted $event): void
    {
        $this->allowance->count($event);
    }
}
