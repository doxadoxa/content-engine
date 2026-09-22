<?php

declare(strict_types=1);

namespace App\Proposals;

use App\Events\PageChangeVerified;
use App\Models\PageProposal;
use App\Models\PageProposalReview;
use App\Models\PagePublication;
use App\Models\PagePublicationCheck;
use App\Models\User;
use App\Pages\TrackedPages;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AssistedPublication
{
    public function __construct(private readonly Proposals $proposals, private readonly TrackedPages $pages, private readonly PublicVerification $verification, private readonly CurrentProject $current) {}

    public function authorize(PageProposal $proposal, User $actor, string $expected): PagePublication
    {
        $this->proposals->owner($actor);
        $this->proposals->expected($proposal, $expected);
        $existing = $proposal->publications()->where('revision_id', $expected)->first();
        abort_if($existing !== null && $existing->mode !== 'assisted', 409, 'This revision already has a native operation. Reconcile it before choosing another method.');
        if ($existing && $existing->status !== 'review_required') {
            $this->validAuthorization($existing, $expected);

            return $existing;
        }
        // Read before authorization: an approved old snapshot is not permission to overwrite a newer public page.
        $page = $proposal->page;
        abort_unless($page !== null && $page->tracked_at !== null, 409);
        $this->pages->capture($this->current->get(), $page);
        $reason = null;
        $publication = DB::transaction(function () use ($proposal, $actor, $expected, &$reason): ?PagePublication {
            $this->proposals->lockProject();
            $proposal = PageProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->proposals->expected($proposal, $expected);
            $revision = $proposal->currentRevision;
            abort_unless($proposal->approved_revision_id === $expected && $proposal->status === 'approved' && $revision !== null, 409, 'Accept this exact revision before authorizing publication.');
            $reason = $this->proposals->invalidReason($proposal, $revision);
            if ($reason !== null) {
                $this->proposals->invalidate($proposal, $reason);

                return null;
            }

            $publication = PagePublication::query()->firstOrCreate(['revision_id' => $revision->id], ['proposal_id' => $proposal->id, 'site_page_id' => $proposal->site_page_id, 'delivery_id' => (string) Str::uuid(), 'mode' => 'assisted', 'status' => 'awaiting_operator', 'authorized_by' => $actor->id, 'authorized_at' => now()]);
            $reactivated = $publication->status === 'review_required';
            $priorAuthorization = $publication->authorized_at?->toIso8601String();
            if ($reactivated) {
                abort_unless($publication->applied_at === null && $publication->verified_at === null, 409, 'Applied publications cannot be reset. Review a new revision.');
                $publication->update(['status' => 'awaiting_operator', 'authorized_by' => $actor->id, 'authorized_at' => now()]);
            }
            if ($publication->wasRecentlyCreated || $reactivated) {
                PageProposalReview::query()->create(['proposal_id' => $proposal->id, 'revision_id' => $revision->id, 'actor_id' => $actor->id, 'action' => 'authorize_publication', 'reason' => $reactivated ? 'Explicitly authorized this handoff again after review.' : 'Explicitly authorized assisted publication.', 'active_seconds' => 0, 'corrections' => ['publication_id' => $publication->id, 'previous_authorized_at' => $reactivated ? $priorAuthorization : null]]);
            }

            return $publication;
        });
        if ($publication === null) {
            throw ValidationException::withMessages(['publication' => $reason]);
        }

        return $publication;
    }

    /** @param array<string, mixed> $input */
    public function applied(PagePublication $publication, User $actor, array $input): void
    {
        $this->proposals->owner($actor);
        abort_unless($publication->mode === 'assisted', 409, 'Native applications are recorded from receiver receipts.');
        $data = Validator::make($input, [
            'applied_by_name' => ['required', 'string', 'max:200'],
            'applied_at' => ['required', 'date', 'before_or_equal:now', 'after_or_equal:'.$publication->authorized_at?->toIso8601String()],
            'application_note' => ['required', 'string', 'max:2000'],
            'confirm_applied' => ['required', 'accepted'],
            'revision_id' => ['required', 'ulid'],
        ])->validate();
        DB::transaction(function () use ($publication, $actor, $data): void {
            $this->proposals->lockProject();
            $publication = PagePublication::query()->lockForUpdate()->findOrFail($publication->id);
            $this->validAuthorization($publication, $data['revision_id']);
            if ($publication->applied_at !== null) {
                return;
            }
            $publication->update(['applied_by' => $actor->id, 'applied_by_name' => $data['applied_by_name'], 'applied_at' => $data['applied_at'], 'application_note' => $data['application_note'], 'status' => 'applied_unverified']);
        });
    }

    public function verify(PagePublication $publication, User $actor): void
    {
        $this->proposals->owner($actor);
        $this->validAuthorization($publication, (string) $publication->revision_id);
        abort_unless($publication->applied_at !== null, 409, 'Record who applied the change and when before verification.');
        if ($publication->verified_at !== null) {
            return;
        }
        $locked = Cache::lock('page-public-verification:'.$publication->id, 90)->get(function () use ($publication): bool {
            $snapshot = null;
            try {
                $page = $publication->page;
                abort_unless($page !== null, 404);
                $captured = $this->pages->capture($this->current->get(), $page);
                $snapshot = $captured->latestSnapshot;
                $results = $this->verification->compare($publication->revision, $snapshot);
            } catch (Throwable) {
                $results = ['passed' => false, 'fields' => [['field' => 'Public read', 'passed' => false, 'detail' => 'The current public page could not be safely read. Check the URL and connection, then retry.']]];
            }
            DB::transaction(function () use ($publication, $snapshot, $results): void {
                $this->proposals->lockProject();
                $publication = PagePublication::query()->lockForUpdate()->findOrFail($publication->id);
                if ($publication->verified_at !== null) {
                    return;
                }
                $proposal = $publication->proposal;
                $reason = $proposal === null || $proposal->approved_revision_id !== $publication->revision_id ? 'The applied revision no longer has current approval.' : $this->proposals->invalidReason($proposal, $publication->revision, false);
                if ($reason !== null) {
                    $results['passed'] = false;
                    $results['fields'][] = ['field' => 'Current approval and evidence', 'passed' => false, 'detail' => $reason];
                }
                PagePublicationCheck::query()->create(['publication_id' => $publication->id, 'snapshot_id' => $snapshot?->id, 'status' => $results['passed'] ? 'verified' : 'unverified', 'results' => $results]);
                $publication->update(['status' => $results['passed'] ? 'verified' : 'verification_failed', 'verified_at' => $results['passed'] ? now() : null, 'verification_snapshot_id' => $snapshot?->id, 'verification_results' => $results]);
                if ($results['passed']) {
                    DB::afterCommit(fn () => event(new PageChangeVerified((string) $publication->project_id, (string) $publication->id)));
                }
            });

            return true;
        });
        abort_unless($locked, 409, 'Verification is already running.');
    }

    public function validAuthorization(PagePublication $publication, string $revisionId): void
    {
        abort_unless($publication->project_id === $this->current->id(), 404);
        abort_unless($publication->status !== 'review_required', 409, 'Authorize assisted publication again before using this invalidated handoff.');
        $proposal = $publication->proposal;
        abort_unless($publication->revision_id === $revisionId && $proposal !== null && $proposal->current_revision_id === $revisionId && $proposal->approved_revision_id === $revisionId, 409, 'This handoff no longer has current approval. Review the current revision.');
        if ($reason = $this->proposals->invalidReason($proposal, $publication->revision, false)) {
            throw ValidationException::withMessages(['publication' => $reason]);
        }
    }

    public function handoff(PagePublication $publication): string
    {
        abort_unless($publication->mode === 'assisted', 409, 'Native operations retain their exact payload in the publication record.');
        $revision = $publication->revision;
        abort_unless($revision !== null, 404);
        $lines = ['# Approved page handoff', '', 'Publication: '.$publication->id, 'Revision: '.$revision->id, 'Page: '.$revision->canonical_url, 'Language: '.$revision->locale, 'Source revision: '.$revision->sourceSnapshot?->revision, '', 'Apply only these changes. First compare each original value with the live editor. If anything differs, stop and request a fresh review. Preserve the URL, layout, forms, metadata and all content outside the changes.', '', 'Downloading this document does not publish anything. Record the actual operator and application time in Avyo, then verify the public result.'];
        foreach ($revision->changes as $index => $change) {
            $lines = [...$lines, '', '## '.($index + 1).'. '.$change['kind'].' — '.$change['operation'], 'Locate: '.$change['locator'], 'Reason: '.$change['reason'], '', 'Original:', $change['before'], '', 'Replacement / paragraph to insert:', $change['after']];
            if ($change['kind'] === 'internal_link') {
                $lines[] = 'Link only “'.$change['anchor_text'].'” to '.$change['target_url'];
            }
        }

        return implode("\n", $lines)."\n";
    }
}
