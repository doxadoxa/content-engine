<?php

declare(strict_types=1);

namespace App\Publishing\Pages;

use App\Events\PageChangeVerified;
use App\Models\PageProposalRevision;
use App\Models\PagePublication;
use App\Models\PagePublicationCheck;
use App\Models\PagePublicationOperation;
use App\Models\User;
use App\Pages\TrackedPages;
use App\Proposals\Proposals;
use App\Proposals\PublicVerification;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class NativePublicVerification
{
    public function __construct(private readonly Proposals $proposals, private readonly EditablePages $editable, private readonly TrackedPages $public, private readonly PublicVerification $verification, private readonly CurrentProject $current) {}

    public function verify(PagePublication $publication, User $actor): void
    {
        $this->proposals->owner($actor);
        abort_unless($publication->project_id === $this->current->id(), 404);
        abort_if($publication->operations()->where('kind', 'recovery')->whereNull('committed_at')->whereIn('status', ['queued', 'sending', 'outcome_unknown', 'blocked_unresolved'])->exists(), 409, 'Resolve the outstanding recovery before verifying the public result.');
        $operation = $publication->operations()->whereNotNull('committed_at')->reorder()->orderByDesc('id')->first();
        abort_unless($operation !== null, 409, 'The native operation has no confirmed result yet. Reconcile it first.');
        if ($operation->verified_at !== null) {
            return;
        }
        $locked = Cache::lock('native-public-verification:'.$publication->id, 90)->get(function () use ($publication, $operation): void {
            $snapshot = null;
            try {
                $page = $publication->page;
                abort_unless($page !== null, 404);
                $cms = $this->editable->capture($page);
                $captured = $this->public->capture($this->current->get(), $page);
                $snapshot = $captured->latestSnapshot;
                $revision = $publication->revision;
                abort_unless($revision !== null && $snapshot !== null, 409);
                if ($operation->kind === 'recovery') {
                    $comparison = new PageProposalRevision(['canonical_url' => $revision->canonical_url, 'locale' => $revision->locale, 'changes' => []]);
                    $comparison->setRelation('sourceSnapshot', $revision->sourceSnapshot);
                    $results = $this->verification->compare($comparison, $snapshot);
                } else {
                    $results = $this->verification->compare($revision, $snapshot);
                }
                $matches = $cms->revision === $operation->afterSnapshot?->revision;
                $results['fields'][] = ['field' => 'Current CMS revision', 'passed' => $matches, 'detail' => $matches ? 'The CMS still matches the exact confirmed operation result.' : 'The CMS was edited again after this operation. Review the current result.'];
                $results['passed'] = $results['passed'] && $matches;
            } catch (Throwable) {
                $results = ['passed' => false, 'fields' => [['field' => 'Public and editable source', 'passed' => false, 'detail' => 'The current sources could not be safely read. No verified result was assumed.']]];
            }
            DB::transaction(function () use ($publication, $operation, $snapshot, $results): void {
                $this->proposals->lockProject();
                $operation = PagePublicationOperation::query()->lockForUpdate()->findOrFail($operation->id);
                $publication = PagePublication::query()->lockForUpdate()->findOrFail($publication->id);
                if ($operation->verified_at !== null) {
                    return;
                }
                if ($operation->kind === 'publish') {
                    $proposal = $publication->proposal;
                    $revision = $publication->revision;
                    $reason = $proposal === null || $revision === null || $proposal->approved_revision_id !== $revision->id ? 'The applied revision no longer has current approval.' : $this->proposals->invalidReason($proposal, $revision, false);
                    if ($reason !== null || $publication->recovered_at !== null) {
                        $results['passed'] = false;
                        $results['fields'][] = ['field' => 'Current approval', 'passed' => false, 'detail' => $reason ?? 'The publication has already been recovered.'];
                    }
                }
                if ($publication->page?->tracked_at === null) {
                    $results['passed'] = false;
                    $results['fields'][] = ['field' => 'Tracking', 'passed' => false, 'detail' => 'Tracking was paused while verification was running.'];
                }
                PagePublicationCheck::query()->create(['publication_id' => $publication->id, 'snapshot_id' => $snapshot?->id, 'status' => $results['passed'] ? 'verified' : 'unverified', 'results' => [...$results, 'operation_id' => $operation->id, 'kind' => $operation->kind]]);
                $operation->update(['status' => $results['passed'] ? 'verified' : 'verification_failed', 'verified_at' => $results['passed'] ? now() : null, 'verification_snapshot_id' => $snapshot?->id, 'verification_results' => $results]);
                if ($operation->kind === 'publish') {
                    $publication->update(['status' => $results['passed'] ? 'verified' : 'verification_failed', 'verified_at' => $results['passed'] ? now() : null, 'verification_snapshot_id' => $snapshot?->id, 'verification_results' => $results]);
                    if ($results['passed']) {
                        DB::afterCommit(fn () => event(new PageChangeVerified($publication->project_id, $publication->id)));
                    }
                } else {
                    // Retain the original verified date; a recovery never restarts uplift measurement.
                    $publication->update(['status' => $results['passed'] ? 'recovered' : 'recovery_verification_failed']);
                }
            });
        });
        abort_unless($locked !== false, 409, 'Verification is already running.');
    }
}
