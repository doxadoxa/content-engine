<?php

declare(strict_types=1);

namespace App\Proposals;

use App\Billing\Entitlements;
use App\Events\PageProposalAccepted;
use App\Feedback\Measurements\PagePerformance;
use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\PageOpportunity;
use App\Models\PageProposal;
use App\Models\PageProposalFact;
use App\Models\PageProposalReview;
use App\Models\PageProposalRevision;
use App\Models\PagePublication;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Opportunities\ExistingPageOverlap;
use App\Pipelines\Core\PipelineRunner;
use App\Publishing\Pages\NativePatchCompiler;
use App\Publishing\Pages\PageReceiverClient;
use App\Publishing\Pages\UnsupportedPageChange;
use App\Support\Http\UnsafePublicUrl;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class Proposals
{
    public function __construct(private readonly CurrentProject $current, private readonly ProposalPatch $patch) {}

    public function owner(User $actor): void
    {
        abort_unless($actor->projects()->whereKey($this->current->id())->wherePivot('role', 'owner')->exists(), 403);
    }

    public function mayGenerate(): void
    {
        $project = $this->current->get();
        $entitlements = app(Entitlements::class);
        $entitlements->forget($project);
        if (($refusal = $entitlements->for($project)->refusal()) !== null) {
            throw ValidationException::withMessages(['generation' => $refusal->message]);
        }
    }

    public function checkGenerationEvidence(PageProposal $proposal): void
    {
        $this->currentReviewedClaim($proposal->generation_context['evidence_snapshot'] ?? []);
    }

    public function begin(PageOpportunity $opportunity, User $actor, ?string $reason = null, ?string $expected = null): PageProposal
    {
        $this->owner($actor);
        abort_unless($opportunity->project_id === $this->current->id(), 404);
        $start = false;
        $proposal = DB::transaction(function () use ($opportunity, $actor, $reason, $expected, &$start): PageProposal {
            $this->lockProject();
            $opportunity = PageOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            $proposal = PageProposal::query()->where('opportunity_id', $opportunity->id)->lockForUpdate()->first();
            abort_unless(in_array($opportunity->status, $proposal === null ? ['open'] : ['open', 'proposed'], true), 409, 'This opportunity was dismissed or withdrawn. Reassess it in Plan before generating changes.');
            if ($proposal !== null && $reason === null) {
                return $proposal;
            }
            $this->mayGenerate();
            $page = SitePage::query()->lockForUpdate()->findOrFail($opportunity->site_page_id);
            abort_unless($page->tracked_at !== null, 409, 'Track the page before proposing a change.');
            $snapshot = $page->snapshots()->where('source_kind', 'public')->first();
            abort_unless($snapshot !== null, 409, 'Capture the public page first.');
            abort_unless(($snapshot->metadata['canonical_url'] ?? null) === $page->canonical_url
                && ($snapshot->metadata['locale'] ?? null) === $page->locale
                && $snapshot->captured_at->greaterThanOrEqualTo(now()->subDays(30)),
                409, 'Capture a fresh public snapshot of this tracked page before requesting changes.');
            $evidence = $opportunity->evidence_snapshot;
            $this->currentReviewedClaim($evidence);
            if ($proposal === null) {
                abort_unless(($evidence['snapshot_id'] ?? null) === $snapshot->id
                    && ($evidence['canonical_url'] ?? null) === $page->canonical_url
                    && ($evidence['locale'] ?? null) === $page->locale
                    && $opportunity->diagnosed_at->greaterThanOrEqualTo(now()->subDays(30)),
                    409, 'The opportunity no longer matches a fresh page snapshot. Refresh its diagnosis in Plan first.');
            } else {
                $evidence = $this->reassessment($opportunity, $page, $snapshot, $actor, (string) $reason);
            }
            if ($proposal !== null) {
                $this->expected($proposal, $expected);
                $this->invalidate($proposal, 'A new proposal revision is being prepared.');
            } else {
                $proposal = PageProposal::query()->create(['opportunity_id' => $opportunity->id, 'site_page_id' => $page->id, 'created_by' => $actor->id]);
            }
            $factIds = BusinessFact::query()->with('currentVersion')->get()->filter(fn (BusinessFact $fact): bool => $fact->currentVersion?->isUsable() === true)->pluck('current_version_id')->values()->all();
            $proposal->update([
                'status' => 'drafting', 'generation_id' => (string) Str::uuid(), 'generation_snapshot_id' => $snapshot->id,
                'generation_context' => ['editable_snapshot_id' => $page->channel_id === null ? null : $page->snapshots()->whereIn('source_kind', ['wordpress', 'webhook'])->first()?->id, 'fact_version_ids' => $factIds, 'evidence_snapshot' => $evidence, 'reason' => $reason, 'actor_id' => $actor->id],
                'invalidation_reason' => null,
            ]);
            $start = true;
            $opportunity->update(['status' => 'proposed']);

            return $proposal;
        });
        if ($start) {
            try {
                app(PipelineRunner::class)->start('page_improvement', $this->current->get(), ['proposal_id' => $proposal->id, 'generation_id' => $proposal->generation_id]);
            } catch (Throwable $exception) {
                $proposal->update(['status' => 'failed', 'invalidation_reason' => 'The proposal could not be queued. Request another revision.']);
                throw $exception;
            }
        }

        return $proposal->fresh();
    }

    /** @param array<string, mixed> $input */
    public function generated(PageProposal $proposal, string $generationId, array $input, string $runId): void
    {
        DB::transaction(function () use ($proposal, $generationId, $input, $runId): void {
            $this->lockProject();
            $proposal = PageProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($proposal->status !== 'drafting' || $proposal->generation_id !== $generationId) {
                return;
            }
            $context = $proposal->generation_context ?? [];
            $this->revision($proposal, $proposal->generationSnapshot, $input, $context['fact_version_ids'] ?? [], $context['evidence_snapshot'] ?? [], $context['reason'] ?? null, $context['actor_id'] ?? null, $runId, $context['editable_snapshot_id'] ?? null);
        });
    }

    /** @param array<string, mixed> $input */
    public function revise(PageProposal $proposal, User $actor, string $expected, array $input, string $reason, int $seconds): PageProposal
    {
        $this->owner($actor);

        return DB::transaction(function () use ($proposal, $actor, $expected, $input, $reason, $seconds): PageProposal {
            $this->lockProject();
            $proposal = PageProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->expected($proposal, $expected);
            abort_unless(! in_array($proposal->status, ['dismissed', 'drafting'], true), 409, 'This proposal is dismissed or a new revision is already being prepared.');
            $previous = $proposal->currentRevision;
            abort_unless($previous !== null, 409);
            $snapshot = $proposal->page?->snapshots()->where('source_kind', 'public')->first();
            abort_unless($snapshot !== null, 409);
            /** @var list<string> $allowed */
            $allowed = BusinessFact::query()->with('currentVersion')->get()->filter(fn (BusinessFact $fact): bool => $fact->currentVersion?->isUsable() === true)->pluck('current_version_id')->values()->all();
            $next = $this->revision($proposal, $snapshot, $input, $allowed, $previous->evidence_snapshot, $reason, $actor->id, null, $proposal->page->snapshots()->whereIn('source_kind', ['wordpress', 'webhook'])->first()?->id);
            $this->review($proposal, $actor, 'revise', $reason, $seconds, ['previous_revision_id' => $previous->id, 'new_revision_id' => $next->id, 'changed_patch' => $previous->patch_hash !== $next->patch_hash, 'changed_evidence' => $previous->facts()->pluck('fact_version_id')->sort()->values()->all() !== $next->facts()->pluck('fact_version_id')->sort()->values()->all()]);

            return $proposal->fresh();
        });
    }

    public function accept(PageProposal $proposal, User $actor, string $expected, int $seconds): void
    {
        $this->owner($actor);
        $reason = DB::transaction(function () use ($proposal, $actor, $expected, $seconds): ?string {
            $this->lockProject();
            $proposal = PageProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->expected($proposal, $expected);
            $revision = $proposal->currentRevision;
            abort_unless($revision !== null && ! in_array($proposal->status, ['drafting', 'dismissed'], true), 409);
            if ($reason = $this->invalidReason($proposal, $revision)) {
                $this->invalidate($proposal, $reason);

                return $reason;
            }
            if ($revision->missing_facts !== [] || $revision->changes === []) {
                return 'Confirm the missing facts and create a complete revision before accepting.';
            }
            foreach ($revision->changes as $change) {
                if ($change['kind'] !== 'internal_link' && $change['fact_version_ids'] === []) {
                    return 'Text changes need supporting owner-confirmed facts. Add the evidence and revise before accepting.';
                }
            }
            if ($proposal->approved_revision_id === $revision->id) {
                return null;
            }
            $proposal->update(['approved_revision_id' => $revision->id, 'status' => 'approved', 'invalidation_reason' => null]);
            $this->review($proposal, $actor, 'accept', null, $seconds);
            event(new PageProposalAccepted((string) $proposal->project_id, (string) $proposal->id, (string) $revision->id));

            return null;
        });
        if ($reason !== null) {
            throw ValidationException::withMessages(['approval' => $reason]);
        }
    }

    public function dismiss(PageProposal $proposal, User $actor, ?string $expected, string $reason, int $seconds): void
    {
        $this->owner($actor);
        DB::transaction(function () use ($proposal, $actor, $expected, $reason, $seconds): void {
            $this->lockProject();
            $proposal = PageProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->expected($proposal, $expected);
            $this->invalidate($proposal, $reason);
            $proposal->update(['status' => 'dismissed', 'generation_id' => null]);
            $proposal->opportunity?->update(['status' => 'dismissed', 'dismissal_reason' => $reason]);
            $this->review($proposal, $actor, 'dismiss', $reason, $seconds);
        });
    }

    public function invalidReason(PageProposal $proposal, PageProposalRevision $revision, bool $checkSource = true): ?string
    {
        $page = $proposal->page;
        if ($page === null || $page->tracked_at === null || $page->canonical_url !== $revision->canonical_url || $page->locale !== $revision->locale) {
            return 'The tracked page identity changed or tracking is paused.';
        }
        if (($revision->compiled_patch['status'] ?? null) === 'supported') {
            try {
                if ($page->channel === null || ! PageReceiverClient::same(app(PageReceiverClient::class)->destination($page->channel, $page), $revision->compiled_patch['destination'])) {
                    return 'The bound CMS object or connection changed. Capture editable source and review a new revision.';
                }
                if ($checkSource && ! $proposal->publications()->where('revision_id', $revision->id)->whereNotNull('applied_at')->exists()) {
                    $latestEditable = $page->snapshots()->whereIn('source_kind', ['wordpress', 'webhook'])->first();
                    if ($latestEditable?->revision !== $revision->editableSnapshot?->revision) {
                        return 'The editable CMS source changed. Capture and review a fresh revision.';
                    }
                }
            } catch (UnsupportedPageChange|UnsafePublicUrl) {
                return 'The native connection is unavailable. Reconnect and review the destination, or use assistance.';
            }
        }
        foreach ($revision->facts()->with(['fact', 'version'])->get() as $evidence) {
            if ($evidence->fact?->current_version_id !== $evidence->fact_version_id || $evidence->version?->isUsable() !== true) {
                return 'A supporting fact changed, expired or lost confirmation. Review a new revision.';
            }
        }
        foreach ($revision->changes as $change) {
            if ($change['kind'] !== 'internal_link') {
                continue;
            }
            $target = SitePage::query()->tracked()->find((string) ($change['target_page_id'] ?? ''));
            if ($target === null || $target->canonical_url !== ($change['target_url'] ?? null) || $target->locale !== $revision->locale
                || parse_url((string) $target->canonical_url, PHP_URL_HOST) !== parse_url((string) $revision->canonical_url, PHP_URL_HOST)) {
                return 'An internal-link target changed identity or tracking was paused. Review a new proposal revision.';
            }
        }
        if ($checkSource && ! $proposal->publications()->where('revision_id', $revision->id)->whereNotNull('applied_at')->exists()) {
            $latest = $page->snapshots()->where('source_kind', 'public')->first();
            if ($latest?->content_hash !== $revision->sourceSnapshot?->content_hash) {
                return 'The captured public page changed. Refresh the proposal against the current source.';
            }
        }

        return null;
    }

    public function invalidateFact(BusinessFact $fact): void
    {
        $ids = PageProposalFact::query()->where('business_fact_id', $fact->id)->pluck('revision_id');
        foreach (PageProposal::query()->whereIn('current_revision_id', $ids)->lockForUpdate()->get() as $proposal) {
            $this->invalidate($proposal, 'A supporting business fact changed. Review a new proposal revision.');
        }
    }

    public function invalidate(PageProposal $proposal, string $reason): void
    {
        // A caller may hold an older route-bound instance; clear persisted approval, not only dirty local fields.
        $proposal->refresh();
        $proposal->update(['approved_revision_id' => null, 'status' => $proposal->status === 'dismissed' ? 'dismissed' : 'review_required', 'invalidation_reason' => $reason]);
        PagePublication::query()->where('proposal_id', $proposal->id)->whereNull('applied_at')->whereNull('verified_at')->update(['status' => 'review_required']);
    }

    public function expected(PageProposal $proposal, ?string $expected): void
    {
        abort_unless($proposal->project_id === $this->current->id(), 404);
        if ($proposal->current_revision_id !== $expected) {
            throw ValidationException::withMessages(['revision' => 'This proposal changed while you were reviewing it. Reload before continuing.']);
        }
    }

    public function lockProject(): void
    {
        Project::query()->whereKey($this->current->id())->lockForUpdate()->firstOrFail();
    }

    /** @param array<string,mixed> $evidence
     * @return list<string>
     */
    private function currentReviewedClaim(array $evidence): array
    {
        if (($evidence['diagnosis_mode'] ?? null) === 'owner_reassessment') {
            $evidence = $evidence['original_evidence'] ?? [];
        }
        if (($evidence['diagnosis_mode'] ?? null) !== 'reviewed_claim') {
            return [];
        }
        /** @var list<string> $ids */
        $ids = array_column($evidence['confirmed_facts'] ?? [], 'version_id');
        abort_unless($ids !== [], 409, 'Recheck the original finding against current confirmed facts before requesting a correction.');
        foreach ($ids as $id) {
            $version = BusinessFactVersion::query()->find((string) $id);
            $isCurrent = $version !== null && $version->isUsable()
                && BusinessFact::query()->whereKey($version->business_fact_id)->where('current_version_id', $version->id)->exists();
            abort_unless($isCurrent, 409, 'A fact behind the reviewed finding changed or expired. Recheck the originating workflow before requesting a correction.');
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function reassessment(PageOpportunity $opportunity, SitePage $page, PageSnapshot $snapshot, User $actor, string $reason): array
    {
        // This owner request re-examines the issue. It never rewrites the historical diagnosis.
        $report = app(PagePerformance::class)->forProject($this->current->get());
        /** @var list<array<string, mixed>> $metrics */
        $metrics = $report['pages'];
        $metric = collect($metrics)->firstWhere('id', $page->id);
        $facts = BusinessFact::query()->with('currentVersion')->get()
            ->filter(fn (BusinessFact $fact): bool => $fact->currentVersion?->isUsable() === true)
            ->map(fn (BusinessFact $fact): array => ['fact_id' => $fact->id, 'version_id' => $fact->current_version_id,
                'statement' => $fact->currentVersion?->statement, 'source_url' => $fact->currentVersion?->source_url,
                'source_note' => $fact->currentVersion?->source_note])->values()->all();

        return [
            'diagnosis_mode' => 'owner_reassessment', 'recorded_by' => $actor->id, 'reassessed_at' => now()->toIso8601String(),
            'original_opportunity_id' => $opportunity->id, 'original_evidence' => $opportunity->evidence_snapshot,
            'owner_request' => $reason, 'snapshot_id' => $snapshot->id, 'source_revision' => $snapshot->revision,
            'canonical_url' => $page->canonical_url, 'locale' => $page->locale, 'captured_at' => $snapshot->captured_at->toIso8601String(),
            'windows' => $report['windows'], 'sources' => $report['sources'],
            'search' => ['current' => $metric['current']['search'] ?? null, 'previous' => $metric['previous']['search'] ?? null],
            'queries' => ['current' => $metric['current']['queries'] ?? [], 'previous' => $metric['previous']['queries'] ?? []],
            'confirmed_facts' => $facts, 'overlap_pages' => app(ExistingPageOverlap::class)->forPage($page),
            'limitations' => ['The original issue may be resolved. Reassess it against the current snapshot and evidence; no change is a valid outcome.', 'Observed performance differences are associations, not proof of a text defect or incremental sales.'],
        ];
    }

    /** @param array<string, mixed> $corrections */
    private function review(PageProposal $proposal, User $actor, string $action, ?string $reason, int $seconds, array $corrections = []): void
    {
        PageProposalReview::query()->create(['proposal_id' => $proposal->id, 'revision_id' => $proposal->current_revision_id, 'actor_id' => $actor->id, 'action' => $action, 'reason' => $reason, 'active_seconds' => min(3600, max(0, $seconds)), 'corrections' => $corrections]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowed
     * @param  array<string, mixed>  $evidence
     */
    private function revision(PageProposal $proposal, PageSnapshot $snapshot, array $input, array $allowed, array $evidence, ?string $reason, ?int $actor, ?string $runId = null, ?string $editableId = null): PageProposalRevision
    {
        try {
            $dependencies = $this->currentReviewedClaim($evidence);
        } catch (HttpException $exception) {
            throw ValidationException::withMessages(['changes' => $exception->getMessage()]);
        }
        $validated = $this->patch->validate($snapshot, $input, $allowed);
        $editable = $editableId === null ? null : PageSnapshot::query()->where('site_page_id', $proposal->site_page_id)->find($editableId);
        $native = app(NativePatchCompiler::class)->compile($proposal->page, $snapshot, $editable, $validated['changes']);
        $revision = PageProposalRevision::query()->create([
            ...$native,
            'proposal_id' => $proposal->id, 'site_page_id' => $proposal->site_page_id, 'source_snapshot_id' => $snapshot->id,
            'number' => (int) $proposal->revisions()->max('number') + 1, 'canonical_url' => $proposal->page->canonical_url, 'locale' => $proposal->page->locale,
            'changes' => $validated['changes'], 'patch_hash' => hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR)),
            'evidence_snapshot' => $evidence, 'measurement_plan' => ['page_id' => $proposal->site_page_id, 'metrics' => ['search_clicks', 'search_impressions', 'recorded_purchases'], 'follow_up_days' => [14, 28], 'start' => 'verified_publication', 'limitations' => 'Observed changes are associations, not proof of incremental sales. Missing or partial data remains unavailable.'],
            'missing_facts' => $validated['missing_facts'], 'no_change_reason' => $validated['no_change_reason'] ?? null, 'revision_reason' => $reason, 'created_by' => $actor, 'pipeline_run_id' => $runId,
        ]);
        $used = array_unique([...$dependencies, ...array_merge(...array_map(fn (array $change): array => $change['fact_version_ids'], $validated['changes']))]);
        foreach (BusinessFactVersion::query()->whereIn('id', $used)->get() as $fact) {
            PageProposalFact::query()->create(['proposal_id' => $proposal->id, 'revision_id' => $revision->id, 'business_fact_id' => $fact->business_fact_id, 'fact_version_id' => $fact->id]);
        }
        $this->invalidate($proposal, 'This revision needs owner review.');
        $proposal->update(['current_revision_id' => $revision->id, 'status' => 'review_required', 'generation_id' => null]);

        return $revision;
    }
}
