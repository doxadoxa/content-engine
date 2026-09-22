<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BusinessFact;
use App\Models\PageOpportunity;
use App\Models\PageProposal;
use App\Models\PageProposalRevision;
use App\Models\PagePublication;
use App\Models\PipelineRun;
use App\Models\SitePage;
use App\Models\User;
use App\Opportunities\PageOutcomeReviews;
use App\Proposals\AssistedPublication;
use App\Proposals\PageBlocks;
use App\Proposals\Proposals;
use App\Publishing\Pages\NativePublicVerification;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class PageProposalController extends Controller
{
    public function __construct(private readonly Proposals $proposals, private readonly AssistedPublication $publication, private readonly PageBlocks $blocks) {}

    public function store(Request $request, PageOpportunity $opportunity): RedirectResponse
    {
        return to_route('proposals.show', $this->proposals->begin($opportunity, $this->actor($request)));
    }

    public function show(PageProposal $proposal): Response
    {
        $proposal->load(['page', 'opportunity', 'currentRevision.sourceSnapshot', 'revisions.facts.version', 'reviews.actor', 'publications.checks', 'publications.operations.attemptsLog']);
        $current = $proposal->currentRevision;
        $invalid = $current ? $this->proposals->invalidReason($proposal, $current) : null;
        $run = PipelineRun::query()->where('pipeline', 'page_improvement')->where('input->proposal_id', $proposal->id)->orderByDesc('id')->first();
        $status = $proposal->status;
        if ($status === 'drafting' && in_array($run?->status->value, ['failed', 'cancelled'], true)) {
            $status = 'failed';
        }

        return Inertia::render('proposals/show', [
            'proposal' => [
                'id' => $proposal->id, 'status' => $invalid !== null && $status === 'approved' ? 'review_required' : $status,
                'current_revision_id' => $proposal->current_revision_id, 'approved_revision_id' => $invalid ? null : $proposal->approved_revision_id,
                'reason' => $invalid ?? $proposal->invalidation_reason,
                'page' => ['id' => $proposal->site_page_id, 'title' => $proposal->page?->title, 'url' => $proposal->page?->canonical_url, 'locale' => $proposal->page?->locale],
                'opportunity' => ['status' => $proposal->opportunity?->status, 'kind' => $proposal->opportunity?->kind, 'issue' => $proposal->opportunity?->diagnosed_issue, 'confidence' => $proposal->opportunity?->confidence],
                'revisions' => $proposal->revisions->map(fn (PageProposalRevision $revision): array => [
                    'id' => $revision->id, 'number' => $revision->number, 'changes' => $revision->changes, 'missing_facts' => $revision->missing_facts,
                    'no_change_reason' => $revision->getAttribute('no_change_reason'), 'editable_snapshot_id' => $revision->editable_snapshot_id, 'compiled_patch' => $revision->compiled_patch, 'evidence_snapshot' => $revision->evidence_snapshot, 'measurement_plan' => $revision->measurement_plan,
                    'reason' => $revision->revision_reason, 'created_at' => $revision->created_at?->toIso8601String(),
                    'snapshot_id' => $revision->source_snapshot_id, 'facts' => $revision->facts->map(fn ($fact) => $fact->version?->only(['id', 'statement', 'source_url', 'source_note', 'confirmed_at', 'review_due_at'])),
                ]),
                'reviews' => $proposal->reviews->map(fn ($review): array => ['id' => $review->id, 'revision_id' => $review->revision_id, 'action' => $review->action, 'reason' => $review->reason, 'active_seconds' => $review->active_seconds, 'actor' => $review->actor?->name, 'created_at' => $review->created_at?->toIso8601String()]),
                'publications' => $proposal->publications->map(fn (PagePublication $publication): array => [...$publication->toArray(), 'outcome' => app(PageOutcomeReviews::class)->context($publication)]),
            ],
            'facts' => BusinessFact::query()->with('currentVersion')->get()->filter(fn (BusinessFact $fact): bool => $fact->currentVersion?->isUsable() === true)->map(fn (BusinessFact $fact): array => ['id' => $fact->current_version_id, 'name' => $fact->name, 'statement' => $fact->currentVersion->statement])->values(),
            'targets' => SitePage::query()->tracked()->where('locale', $proposal->page?->locale)->whereKeyNot($proposal->site_page_id)->get(['id', 'title', 'canonical_url']),
            'source' => $current?->sourceSnapshot ? ['id' => $current->source_snapshot_id, 'captured_at' => $current->sourceSnapshot->captured_at->toIso8601String(), 'revision' => $current->sourceSnapshot->revision, 'fields' => $current->sourceSnapshot->fields, 'blocks' => $this->blocks->from($current->sourceSnapshot)] : null,
        ]);
    }

    public function revise(Request $request, PageProposal $proposal): RedirectResponse
    {
        $data = $request->validate(['revision_id' => ['required', 'ulid'], 'reason' => ['required', 'string', 'max:2000'], 'active_seconds' => ['required', 'integer', 'min:0', 'max:3600']]);
        $this->proposals->revise($proposal, $this->actor($request), $data['revision_id'], $request->only(['changes', 'missing_facts', 'no_change_reason']), $data['reason'], $data['active_seconds']);

        return back();
    }

    public function regenerate(Request $request, PageProposal $proposal): RedirectResponse
    {
        $data = $request->validate(['revision_id' => ['nullable', 'ulid'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->proposals->begin($proposal->opportunity, $this->actor($request), $data['reason'], $data['revision_id'] ?? null);

        return back();
    }

    public function accept(Request $request, PageProposal $proposal): RedirectResponse
    {
        $data = $request->validate(['revision_id' => ['required', 'ulid'], 'active_seconds' => ['required', 'integer', 'min:0', 'max:3600']]);
        $this->proposals->accept($proposal, $this->actor($request), $data['revision_id'], $data['active_seconds']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'This revision is accepted. Publication is a separate action.']);

        return back();
    }

    public function dismiss(Request $request, PageProposal $proposal): RedirectResponse
    {
        $data = $request->validate(['revision_id' => ['nullable', 'ulid'], 'reason' => ['required', 'string', 'max:2000'], 'active_seconds' => ['required', 'integer', 'min:0', 'max:3600']]);
        $this->proposals->dismiss($proposal, $this->actor($request), $data['revision_id'] ?? null, $data['reason'], $data['active_seconds']);

        return back();
    }

    public function publish(Request $request, PageProposal $proposal): RedirectResponse
    {
        $data = $request->validate(['revision_id' => ['required', 'ulid']]);
        try {
            $this->publication->authorize($proposal, $this->actor($request), $data['revision_id']);
        } catch (UnsafePublicUrl|ConnectionException|\InvalidArgumentException) {
            throw ValidationException::withMessages(['publication' => 'The page could not be safely checked. Reconnect or retry before preparing the handoff.']);
        }

        return back();
    }

    public function handoff(PagePublication $publication): HttpResponse
    {
        $this->publication->validAuthorization($publication, (string) $publication->revision_id);

        return response($this->publication->handoff($publication), 200, ['Content-Type' => 'text/markdown; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="page-handoff-'.$publication->id.'.md"', 'Cache-Control' => 'no-store']);
    }

    public function applied(Request $request, PagePublication $publication): RedirectResponse
    {
        $this->publication->applied($publication, $this->actor($request), $request->all());

        return back();
    }

    public function verify(Request $request, PagePublication $publication): RedirectResponse
    {
        if ($publication->mode === 'assisted') {
            $this->publication->verify($publication, $this->actor($request));
        } else {
            app(NativePublicVerification::class)->verify($publication, $this->actor($request));
        }

        return back();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
