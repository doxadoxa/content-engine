<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\User;
use App\Pages\BusinessFacts;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BusinessFactController extends Controller
{
    public function __construct(private readonly CurrentProject $current, private readonly BusinessFacts $facts) {}

    public function index(): Response
    {
        return Inertia::render('business-facts/index', [
            'facts' => BusinessFact::query()->with(['currentVersion.confirmer', 'versions.confirmer'])->orderBy('name')->get()->map(fn (BusinessFact $fact): array => [
                'id' => $fact->id, 'name' => $fact->name, 'current_version_id' => $fact->current_version_id,
                'current' => $fact->currentVersion === null ? null : $this->version($fact->currentVersion),
                'versions' => $fact->versions->map(fn (BusinessFactVersion $version): array => $this->version($version)),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $project = $this->current->get() ?? abort(404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $this->facts->save($project, $actor, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Business fact recorded with its evidence and confirmation status.']);

        return back();
    }

    public function update(Request $request, BusinessFact $fact): RedirectResponse
    {
        $project = $this->current->get() ?? abort(404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $this->facts->save($project, $actor, $request->all(), $fact);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'A new fact version was saved. Earlier evidence remains unchanged.']);

        return back();
    }

    /** @return array<string, mixed> */
    private function version(BusinessFactVersion $version): array
    {
        return [
            'id' => $version->id, 'version' => $version->version, 'statement' => $version->statement,
            'source_url' => $version->source_url, 'source_note' => $version->source_note,
            'status' => $version->status, 'usable' => $version->isUsable(),
            'confirmed_at' => $version->confirmed_at?->toIso8601String(), 'confirmed_by' => $version->confirmed_by, 'confirmed_by_name' => $version->confirmer?->name,
            'review_due_at' => $version->review_due_at?->toDateString(),
        ];
    }
}
