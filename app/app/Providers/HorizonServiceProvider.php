<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Who may open /horizon outside local.
     *
     * Horizon aggregates every tenant's queues and failed payloads, so ordinary
     * project membership is not sufficient — this is the same question the
     * administrative panel asks, and it is now answered by the same column.
     *
     * `HORIZON_ALLOWED_EMAILS` bootstraps the column and is not consulted
     * here. Leaving it as a second way in would have kept every fault the move
     * was meant to fix: an address on that list would still reach every
     * tenant's failed payloads after its `is_admin` flag had been revoked, and
     * an account registered later with such an address — now that anybody can
     * register — would acquire the access without anybody granting it.
     *
     * The list still does the one job it is good at. The migration grants the
     * flag to the addresses on it, and `DatabaseSeeder` grants it to the seeded
     * account when no administrator exists at all, so a fresh deployment still
     * has a way in. What it no longer does is answer this question.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->is_admin === true);
    }
}
