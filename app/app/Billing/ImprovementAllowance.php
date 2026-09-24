<?php

declare(strict_types=1);

namespace App\Billing;

use App\Events\PageProposalAccepted;
use App\Models\PageProposal;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ImprovementAllowance
{
    public function __construct(private readonly CurrentProject $current, private readonly Entitlements $entitlements) {}

    public function count(PageProposalAccepted $event): void
    {
        $project = Project::query()->findOrFail($event->projectId);
        $this->current->run($project, fn () => DB::transaction(function () use ($project, $event): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $proposal = PageProposal::query()->findOrFail($event->proposalId);
            abort_unless($proposal->approved_revision_id === $event->revisionId, 409);
            if (DB::table('page_improvement_allowances')->where('project_id', $project->id)->where('proposal_id', $proposal->id)->exists()) {
                return;
            }
            $this->entitlements->forget($project);
            $entitlement = $this->entitlements->for($project);
            if (! $entitlement->mayPublish()) {
                throw ValidationException::withMessages(['approval' => 'An active plan or available publication grace is required for a first acceptance. The draft remains saved.']);
            }
            $counted = $entitlement->plan !== null;
            if ($counted) {
                if (! $this->entitlements->reserve($project, Metric::PageImprovements)) {
                    throw ValidationException::withMessages(['approval' => 'This plan has no remaining page-improvement allowance. The draft is saved; no approval or additional charge was recorded.']);
                }
            }
            DB::table('page_improvement_allowances')->insert([
                'id' => (string) Str::ulid(), 'project_id' => $project->id, 'proposal_id' => $proposal->id,
                'period_started_at' => $entitlement->subscription?->periodStart(),
                'units' => $counted ? 1 : 0, 'accepted_at' => now(), 'policy' => 'first_owner_acceptance_per_proposal_v1',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }));
    }
}
