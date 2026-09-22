<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PageOpportunity;
use App\Models\PageOpportunityScan;
use App\Models\PageProposal;
use App\Models\SitePage;
use App\Opportunities\DiagnoseOpportunities;
use App\Opportunities\RecordOwnerOpportunity;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class PageOpportunityController extends Controller
{
    public function index(): Response
    {
        $open = PageOpportunity::query()->where('status', 'open')->whereHas('page', fn ($query) => $query->whereNotNull('tracked_at'))->with('page')->get()
            ->sortByDesc(fn (PageOpportunity $opportunity): int => (int) ($opportunity->ranking_factors['priority_points'] ?? 0))->take(5)->values();
        $ongoing = PageProposal::query()->whereIn('status', ['drafting', 'review_required', 'approved', 'failed'])->whereHas('page')->with('page')->latest()->limit(20)->get();
        $scan = PageOpportunityScan::query()->latest('id')->first();

        return Inertia::render('opportunities/index', [
            'pages' => SitePage::query()->tracked()->where('page_kind', 'commercial')->with('latestSnapshot')->orderBy('url')->get()->map(fn (SitePage $page): array => ['id' => $page->id, 'title' => $page->title, 'snapshot_id' => $page->latestSnapshot?->id]),
            'opportunities' => $open->map(fn (PageOpportunity $opportunity): array => $this->summary($opportunity)),
            'ongoing' => $ongoing->map(fn (PageProposal $proposal): array => [
                'id' => $proposal->id, 'status' => $proposal->status,
                'page_title' => $proposal->page->title, 'page_url' => $proposal->page->canonical_url ?? $proposal->page->url,
            ]),
            'history' => PageOpportunity::query()->whereIn('status', ['dismissed', 'withdrawn'])->with('page')->latest('updated_at')->limit(20)->get()->map(fn (PageOpportunity $opportunity): array => $this->summary($opportunity)),
            'scan' => $scan === null ? null : ['at' => $scan->created_at->toIso8601String(), 'tracked_pages' => $scan->tracked_pages, 'notes' => $scan->notes],
        ]);
    }

    public function store(Request $request, CurrentProject $current, RecordOwnerOpportunity $recorder): RedirectResponse
    {
        $data = $request->validate([
            'site_page_id' => ['required', 'ulid'], 'snapshot_id' => ['required', 'ulid'],
            'quoted_excerpt' => ['required', 'string', 'min:8', 'max:1200'],
            'additional_excerpt' => ['nullable', 'string', 'min:8', 'max:1200'],
            'question' => ['required', 'string', 'min:12', 'max:1000'],
        ]);
        $recorder->record($current->get() ?? abort(404), SitePage::query()->findOrFail((string) $data['site_page_id']), $data, (int) $request->user()->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'The question and exact page evidence are saved for review. No business fact was confirmed.']);

        return to_route('opportunities.index');
    }

    public function refresh(DiagnoseOpportunities $diagnosis, CurrentProject $current): RedirectResponse
    {
        $project = $current->get() ?? abort(404);
        $count = $diagnosis->refresh($project);
        Inertia::flash('toast', ['type' => 'success', 'message' => $count > 0 ? 'The strongest supported opportunities are ready to review.' : 'No new change is justified by the available evidence.']);

        return to_route('opportunities.index');
    }

    public function dismiss(Request $request, PageOpportunity $opportunity): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        DB::transaction(function () use ($opportunity, $data): void {
            $locked = PageOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            abort_unless($locked->status === 'open', 409, 'This opportunity has changed. Review its current state.');
            $locked->update(['status' => 'dismissed', 'dismissal_reason' => $data['reason']]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Decision recorded. Refreshing evidence will not reopen this recommendation.']);

        return back();
    }

    public function reconsider(Request $request, PageOpportunity $opportunity, CurrentProject $current, RecordOwnerOpportunity $recorder): RedirectResponse
    {
        abort_if(($opportunity->evidence_snapshot['diagnosis_mode'] ?? '') === 'reviewed_claim', 409, 'Recheck this discrepancy from AI answers or fact maintenance. Its original owner review must not be replaced by an automatic diagnosis.');
        if (($opportunity->evidence_snapshot['diagnosis_mode'] ?? '') === 'owner') {
            $page = $opportunity->page->load('latestSnapshot');
            $recorder->record($current->get() ?? abort(404), $page, [
                'snapshot_id' => $page->latestSnapshot->id ?? '',
                'quoted_excerpt' => (string) ($opportunity->evidence_snapshot['quoted_excerpt'] ?? ''),
                'additional_excerpt' => $opportunity->evidence_snapshot['additional_excerpt'] ?? null,
                'question' => $opportunity->missing_fact_questions[0] ?? '',
            ], (int) $request->user()->id, reactivate: true);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'The quoted question still matches the current snapshot and is ready for review again. The earlier decision is retained.']);

            return back();
        }
        DB::transaction(function () use ($opportunity): void {
            $locked = PageOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            abort_unless($locked->status === 'dismissed', 409, 'Only a dismissed opportunity can be reconsidered.');
            $locked->update(['status' => 'withdrawn']);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Refresh evidence to check whether this recommendation is still justified. The earlier reason is retained.']);

        return back();
    }

    /** @return array<string, mixed> */
    private function summary(PageOpportunity $opportunity): array
    {
        return [
            'id' => $opportunity->id, 'kind' => $opportunity->kind, 'status' => $opportunity->status,
            'page' => ['id' => $opportunity->page->id, 'title' => $opportunity->page->title, 'url' => $opportunity->page->canonical_url ?? $opportunity->page->url, 'locale' => $opportunity->page->locale],
            'diagnosed_issue' => $opportunity->diagnosed_issue, 'suggested_scope' => $opportunity->suggested_scope,
            'confidence' => $opportunity->confidence, 'effort' => $opportunity->effort,
            'ranking_factors' => $opportunity->ranking_factors, 'missing_fact_questions' => $opportunity->missing_fact_questions,
            'evidence' => $opportunity->evidence_snapshot, 'dismissal_reason' => $opportunity->dismissal_reason,
            'diagnosed_at' => $opportunity->diagnosed_at->toIso8601String(),
        ];
    }
}
