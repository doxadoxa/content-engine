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

    public function validate(string $key): Plan
    {
        if (! $this->plans->has($key) || ! $this->plans->get($key)->selfServe) {
            throw ValidationException::withMessages(['plan' => 'Choose one of the current plans before continuing.']);
        }

        return $this->plans->get($key);
    }

    public function selected(Request $request, ?Project $project = null): Plan
    {
        $choice = $project?->onboarding['offer'] ?? $request->session()->get(self::SESSION_KEY);
        $key = is_array($choice) ? ($choice['key'] ?? null) : null;

        if (is_string($key) && $this->plans->has($key) && $this->plans->get($key)->selfServe) {
            return $this->plans->get($key);
        }

        return $this->plans->defaultPlan();
    }

    /** @return array{key: string} */
    public function identity(Plan $plan): array
    {
        return ['key' => $plan->key];
    }
}
