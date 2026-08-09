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
     * project membership is not sufficient. The explicit allow-list defaults
     * to nobody and is configured independently from project roles.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            if ($user === null) {
                return false;
            }

            return in_array(strtolower($user->email), config('horizon.allowed_emails', []), true);
        });
    }
}
