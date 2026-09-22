<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PlanSelection
{
    public const SESSION_KEY = 'billing.selected_offer';

    public function __construct(private readonly PlanCatalog $plans) {}

    public function validate(string $key, int $version): Plan
    {
        if ($version !== $this->plans->currentVersion() || ! $this->plans->has($key, $version)
            || ! $this->plans->get($key, $version)->selfServe) {
            throw ValidationException::withMessages(['plan' => 'Choose one of the current plans before continuing.']);
        }

        return $this->plans->get($key, $version);
    }

    public function selected(Request $request, ?Project $project = null): Plan
    {
        $choice = $project?->onboarding['offer'] ?? $request->session()->get(self::SESSION_KEY);
        if (is_array($choice) && is_string($choice['key'] ?? null) && is_numeric($choice['version'] ?? null)) {
            if ($this->plans->has($choice['key'], (int) $choice['version']) && $this->plans->get($choice['key'], (int) $choice['version'])->selfServe) {
                return $this->plans->get($choice['key'], (int) $choice['version']);
            }
        }

        return $this->plans->defaultPlan();
    }

    /** @return array{key: string, version: int} */
    public function identity(Plan $plan): array
    {
        return ['key' => $plan->key, 'version' => $plan->version];
    }
}
