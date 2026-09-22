<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\DeliveryEconomics;
use App\Billing\ServiceEffort;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

final class DeliveryEconomicsController extends Controller
{
    public function index(Request $request, CurrentProject $current, DeliveryEconomics $economics): Response
    {
        $project = $current->get();
        abort_if($project === null, 404);
        $input = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $input['month'] ?? now('UTC')->format('Y-m');
        $start = Carbon::createFromFormat('!Y-m', $month, 'UTC');
        abort_if($start === null, 422);

        return Inertia::render('delivery-economics/index', ['month' => $month, 'report' => $economics->report($project, $start, $start->copy()->addMonth()), 'categories' => ServiceEffort::CATEGORIES]);
    }

    public function store(Request $request, CurrentProject $current, ServiceEffort $effort): RedirectResponse
    {
        $project = $current->get();
        abort_if($project === null, 404);
        $actor = $request->user();
        abort_if($actor === null, 403);
        $effort->record($project, $actor, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Delivery time recorded. Earlier entries remain in the history.']);

        return back();
    }
}
