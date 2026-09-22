<?php

declare(strict_types=1);

namespace App\Publishing\Pages;

use App\Models\PageProposal;
use App\Models\PageProposalReview;
use App\Models\PagePublication;
use App\Models\PagePublicationOperation;
use App\Models\User;
use App\Pages\TrackedPages;
use App\Proposals\Proposals;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class NativePublication
{
    public function __construct(private readonly Proposals $proposals, private readonly EditablePages $editable, private readonly TrackedPages $public, private readonly CurrentProject $current) {}

    public function authorize(PageProposal $proposal, User $actor, string $revisionId): PagePublicationOperation
    {
        $this->proposals->owner($actor);
        $this->proposals->expected($proposal, $revisionId);
        $existing = PagePublicationOperation::query()->whereHas('publication', fn ($query) => $query->where('revision_id', $revisionId))->where('kind', 'publish')->first();
        if ($existing !== null) {
            return $existing;
        }
        $page = $proposal->page;
        abort_unless($page !== null && $proposal->approved_revision_id === $revisionId, 409, 'Accept this exact revision first.');
        $this->public->capture($this->current->get(), $page);
        $source = $this->editable->capture($page);
        $result = DB::transaction(function () use ($proposal, $actor, $revisionId, $source): PagePublicationOperation|string {
            $this->proposals->lockProject();
            $proposal = PageProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->proposals->expected($proposal, $revisionId);
            $revision = $proposal->currentRevision;
            abort_unless($revision !== null && $proposal->approved_revision_id === $revisionId && $proposal->status === 'approved', 409, 'Review the current revision before publishing.');
            $patch = $revision->compiled_patch;
            if (($patch['status'] ?? null) !== 'supported') {
                return 'This revision has no supported native payload. Request a new reviewed revision or use an assisted handoff.';
            }
            $reason = $this->proposals->invalidReason($proposal, $revision);
            if ($reason !== null || $source->revision !== $patch['expected_revision']) {
                $this->proposals->invalidate($proposal, $reason ?? 'The CMS revision changed. Capture and review a fresh proposal.');

                return $reason ?? 'The CMS revision changed. Capture and review a fresh proposal.';
            }
            // Unknown outcomes on this object must be reconciled before any later write is authorized.
            abort_if(PagePublicationOperation::query()->where('site_page_id', $proposal->site_page_id)->whereIn('status', ['queued', 'sending', 'outcome_unknown', 'blocked_unresolved'])->exists(), 409, 'Resolve the outstanding operation on this page first.');
            $publication = PagePublication::query()->where('revision_id', $revisionId)->first();
            abort_if($publication !== null, 409, 'This revision already has an assisted publication record. Finish or replace that revision before choosing another method.');
            $id = (string) Str::uuid();
            $publication = PagePublication::query()->create(['proposal_id' => $proposal->id, 'revision_id' => $revisionId, 'site_page_id' => $proposal->site_page_id, 'delivery_id' => $id, 'mode' => $patch['destination']['type'], 'status' => 'queued', 'authorized_by' => $actor->id, 'authorized_at' => now()]);
            $operation = $this->operation($publication, $actor, $patch['destination'], ['operation_id' => $id, 'expected_revision' => $patch['expected_revision'], 'patches' => $patch['patches']], 'publish', $source->id);
            PageProposalReview::query()->create(['proposal_id' => $proposal->id, 'revision_id' => $revisionId, 'actor_id' => $actor->id, 'action' => 'authorize_native_publication', 'reason' => 'Explicitly authorized the exact reviewed CMS patch.', 'active_seconds' => 0, 'corrections' => ['publication_id' => $publication->id, 'operation_id' => $operation->id]]);
            DB::afterCommit(fn () => DispatchPageOperation::dispatch($operation->id)->onQueue((string) config('publishing.queue', 'pipeline')));

            return $operation;
        });
        if (is_string($result)) {
            throw ValidationException::withMessages(['publication' => $result]);
        }

        return $result->fresh();
    }

    public function recover(PagePublication $publication, User $actor): PagePublicationOperation
    {
        $this->proposals->owner($actor);
        abort_unless($publication->project_id === $this->current->id(), 404);
        $original = $publication->operations()->where('kind', 'publish')->first();
        abort_unless($original !== null && $original->after_snapshot_id !== null && $original->committed_at !== null, 409, 'Reconcile the original publication before recovery.');
        $existing = PagePublicationOperation::query()->where('recovery_of_id', $original->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        $page = $publication->page;
        abort_unless($page !== null, 404);
        $current = $this->editable->capture($page);

        return DB::transaction(function () use ($publication, $original, $current, $actor): PagePublicationOperation {
            $this->proposals->lockProject();
            $publication = PagePublication::query()->lockForUpdate()->findOrFail($publication->id);
            $original = PagePublicationOperation::query()->findOrFail($original->id);
            $destination = $current->metadata['destination'] ?? [];
            abort_unless($current->revision === $original->afterSnapshot?->revision && PageReceiverClient::sameDestinationIgnoringCredentials($destination, $original->destination), 409, 'The CMS changed after this publication. Automatic recovery would overwrite another edit; use an assisted recovery.');
            abort_if(PagePublicationOperation::query()->where('site_page_id', $publication->site_page_id)->whereIn('status', ['queued', 'sending', 'outcome_unknown', 'blocked_unresolved'])->exists(), 409, 'Resolve the outstanding operation on this page first.');
            $existing = PagePublicationOperation::query()->where('recovery_of_id', $original->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            // This is new explicit recovery permission. Pin the current credentials,
            // while retaining every original object/account identity constraint.
            $operation = $this->operation($publication, $actor, $destination, ['operation_id' => (string) Str::uuid(), 'expected_revision' => $current->revision, 'restores_operation_id' => $original->delivery_id], 'recovery', $current->id, $original->id);
            PageProposalReview::query()->create(['proposal_id' => $publication->proposal_id, 'revision_id' => $publication->revision_id, 'actor_id' => $actor->id, 'action' => 'authorize_recovery', 'reason' => 'Explicitly authorized restoring only this publication’s original fields.', 'active_seconds' => 0, 'corrections' => ['publication_id' => $publication->id, 'operation_id' => $operation->id]]);
            DB::afterCommit(fn () => DispatchPageOperation::dispatch($operation->id)->onQueue((string) config('publishing.queue', 'pipeline')));

            return $operation;
        });
    }

    /** @param array<string,mixed> $destination
     * @param  array<string,mixed>  $request
     */
    private function operation(PagePublication $publication, User $actor, array $destination, array $request, string $kind, string $before, ?string $recoveryOf = null): PagePublicationOperation
    {
        $body = json_encode($request, JSON_THROW_ON_ERROR);

        return PagePublicationOperation::query()->create(['publication_id' => $publication->id, 'site_page_id' => $publication->site_page_id, 'channel_id' => $destination['channel_id'], 'kind' => $kind, 'status' => 'queued', 'delivery_id' => $request['operation_id'], 'request_body' => $body, 'request_hash' => hash('sha256', $body), 'destination' => $destination, 'before_snapshot_id' => $before, 'recovery_of_id' => $recoveryOf, 'authorized_by' => $actor->id, 'authorized_at' => now()]);
    }
}
