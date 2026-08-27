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
     * The environment allow-list is kept, and only for what it is good at:
     * naming the first administrator on a fresh deployment, when there is
     * nobody who could grant the flag to anybody. As a permission model it was
     * never one — it cannot be revoked without a deploy, it records nothing
     * about who is on it, and an address changing hands transfers access to
     * every tenant's failed payloads silently.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            if ($user === null) {
                return false;
            }

            return $user->is_admin
                || in_array(strtolower($user->email), config('horizon.allowed_emails', []), true);
        });
    }
}
