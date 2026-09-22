<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\PlanCatalog;
use App\Billing\PlanSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PlanSelectionController extends Controller
{
    public function __invoke(Request $request, PlanSelection $selection, PlanCatalog $plans): RedirectResponse
    {
        $validated = $request->validate(['plan' => ['sometimes', 'string'], 'plan_version' => ['sometimes', 'integer']]);
        $plan = $selection->validate($validated['plan'] ?? $plans->defaultPlan()->key, (int) ($validated['plan_version'] ?? $plans->currentVersion()));
        $request->session()->put(PlanSelection::SESSION_KEY, $selection->identity($plan));

        return to_route($request->user() === null ? 'register' : 'onboarding.show');
    }
}
