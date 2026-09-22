<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PageChangeVerified;
use App\Feedback\Followups\StartChangeFollowups;

final class StartVerifiedPageFollowups
{
    public function __construct(private readonly StartChangeFollowups $followups) {}

    public function handle(PageChangeVerified $event): void
    {
        $this->followups->start($event->projectId, $event->publicationId);
    }
}
