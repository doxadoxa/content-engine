<?php

declare(strict_types=1);

namespace App\FactMaintenance;

use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\FactMaintenanceClaim;
use App\Models\FactUsageImpact;
use App\Models\PageProposalFact;
use App\Models\PagePublication;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;

/** Called under BusinessFacts' existing project lock; history is retained, not rewritten. */
final class FactUsageImpacts
{
    public function reconcile(): void
    {
        $project = app(CurrentProject::class)->get() ?? abort(404);
        DB::transaction(function () use ($project): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            foreach (BusinessFact::query()->with('currentVersion')->get() as $fact) {
                if ($fact->currentVersion !== null) {
                    $this->changed($fact, $fact->currentVersion);
                }
            }
        });
    }

    public function changed(BusinessFact $fact, BusinessFactVersion $replacement): void
    {
        $oldIds = $fact->versions()->whereKeyNot($replacement->id)->pluck('id')->all();
        foreach (FactMaintenanceClaim::query()->whereIn('fact_version_id', $oldIds)->get() as $claim) {
            FactUsageImpact::query()->firstOrCreate(['new_fact_version_id' => $replacement->id, 'origin_type' => 'checked_claim', 'origin_id' => $claim->id], [
                'site_page_id' => $claim->site_page_id, 'previous_fact_version_id' => $claim->fact_version_id,
                'evidence' => ['exact_quote' => $claim->exact_quote, 'relation' => $claim->relation, 'source' => $claim->source,
                    'snapshot_id' => $claim->source_snapshot_id, 'new_status' => $replacement->status,
                    'reason' => 'A fact used by this earlier assessment changed. A fresh page check is required; this is not proof that the page is now wrong.'],
            ]);
        }
        foreach (PageProposalFact::query()->where('business_fact_id', $fact->id)->whereIn('fact_version_id', $oldIds)->get() as $reference) {
            foreach (PagePublication::query()->where('revision_id', $reference->revision_id)->whereNotNull('applied_at')->get() as $publication) {
                FactUsageImpact::query()->firstOrCreate(['new_fact_version_id' => $replacement->id, 'origin_type' => 'publication', 'origin_id' => $publication->id], [
                    'site_page_id' => $publication->site_page_id, 'previous_fact_version_id' => $reference->fact_version_id,
                    'evidence' => ['proposal_id' => $publication->proposal_id, 'revision_id' => $reference->revision_id,
                        'applied_at' => $publication->applied_at?->toIso8601String(), 'recovered_at' => $publication->recovered_at?->toIso8601String(),
                        'new_status' => $replacement->status, 'reason' => 'A published revision referenced an earlier version of this fact. Its historical approval remains; inspect the current page before requesting any correction.'],
                ]);
            }
        }
    }
}
