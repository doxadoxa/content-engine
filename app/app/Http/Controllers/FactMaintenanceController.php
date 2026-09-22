<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\FactMaintenance\FactMaintenance;
use App\FactMaintenance\ReviewFactClaim;
use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\FactMaintenanceCheck;
use App\Models\FactMaintenanceClaim;
use App\Models\FactMaintenanceResult;
use App\Models\FactMaintenanceReview;
use App\Models\FactUsageImpact;
use App\Models\PageSnapshot;
use App\Models\SitePage;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class FactMaintenanceController extends Controller
{
    public function __construct(private readonly CurrentProject $current, private readonly FactMaintenance $maintenance) {}

    public function index(): Response
    {
        $checks = FactMaintenanceCheck::query()->orderByDesc('id')->limit(50)->get();
        $impacts = FactUsageImpact::query()->orderByDesc('id')->limit(100)->get();
        $versions = BusinessFactVersion::query()->whereIn('id', $impacts->pluck('previous_fact_version_id')->merge($impacts->pluck('new_fact_version_id'))->unique())->get()->keyBy('id');

        return Inertia::render('fact-maintenance/index', ['pages' => SitePage::query()->tracked()->orderBy('canonical_url')->get(['id', 'canonical_url', 'title', 'locale']),
            'facts' => $this->facts(), 'checks' => $checks, 'impacts' => $impacts->map(fn (FactUsageImpact $impact): array => [...$impact->toArray(),
                'previous_fact' => $versions->get($impact->previous_fact_version_id), 'new_fact' => $versions->get($impact->new_fact_version_id)])->all()]);
    }

    public function show(FactMaintenanceCheck $check, ReviewFactClaim $reviews): Response
    {
        $claims = FactMaintenanceClaim::query()->where('check_id', $check->id)->get();
        $history = FactMaintenanceReview::query()->whereIn('claim_id', $claims->modelKeys())->orderByDesc('id')->get()->groupBy('claim_id');
        $snapshot = $check->source_snapshot_id === null ? null : PageSnapshot::query()->find($check->source_snapshot_id);

        return Inertia::render('fact-maintenance/show', ['check' => $check, 'result' => FactMaintenanceResult::query()->where('check_id', $check->id)->first(),
            'current_reason' => $this->maintenance->currentReason($check), 'facts' => $this->facts(),
            'selected_facts' => BusinessFactVersion::query()->whereIn('id', $check->specification['fact_version_ids'])->get(),
            'claims' => $claims->map(fn (FactMaintenanceClaim $claim): array => [...$claim->toArray(), 'reviews' => $history->get($claim->id, collect())->values()->all(),
                'correction_mode' => $snapshot !== null && $reviews->proposalSupported($claim, $snapshot) ? 'proposal' : 'assisted'])->all()]);
    }

    public function start(Request $request): RedirectResponse
    {
        $project = $this->current->get() ?? abort(404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $checks = $this->maintenance->start($project, $actor, $request->all());

        return count($checks) === 1 ? to_route('fact-maintenance.show', $checks[0]) : to_route('fact-maintenance.index');
    }

    public function review(Request $request, FactMaintenanceClaim $claim, ReviewFactClaim $reviews): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $review = $reviews->review($claim, $actor, $request->all());

        return isset($review->evidence['opportunity_id']) ? to_route('opportunities.index') : back();
    }

    /** @return list<array<string,mixed>> */
    private function facts(): array
    {
        return array_values(BusinessFact::query()->with('currentVersion')->orderBy('name')->get()->map(fn (BusinessFact $fact): array => ['id' => $fact->id, 'name' => $fact->name,
            'current' => $fact->currentVersion, 'usable' => $fact->currentVersion?->isUsable() ?? false])->all());
    }
}
