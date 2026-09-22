<?php

declare(strict_types=1);

namespace App\Feedback\Followups;

use App\Models\PageChangeBaseline;
use App\Models\PageChangeFollowup;
use App\Models\PageProposalRevision;
use App\Models\PagePublication;
use App\Models\PageSnapshot;
use App\Purchases\PurchaseReport;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class StartChangeFollowups
{
    public function __construct(private readonly CurrentProject $current, private readonly SearchWindowEvidence $search, private readonly PurchaseReport $purchases) {}

    public function start(string $projectId, string $publicationId): void
    {
        $this->current->run($projectId, fn () => DB::transaction(function () use ($publicationId): void {
            $publication = PagePublication::query()->whereKey($publicationId)->lockForUpdate()->firstOrFail();
            if ($publication->status !== 'verified' || $publication->site_page_id === null || $publication->proposal_id === null || $publication->revision_id === null || $publication->verified_at === null || $publication->verification_snapshot_id === null || $publication->verified_at->isFuture()) {
                throw new LogicException('Follow-up starts only after a recorded live verification.');
            }
            PageSnapshot::query()->whereKey($publication->verification_snapshot_id)->where('site_page_id', $publication->site_page_id)->where('source_kind', 'public')->firstOrFail();
            $at = CarbonImmutable::instance($publication->verified_at);
            $baseline = PageChangeBaseline::query()->where('revision_id', $publication->revision_id)->first()
                ?? $this->missingBaseline($publication, $at);
            foreach ([14, 28] as $days) {
                $window = ObservationWindow::afterPublication($at, $days);
                PageChangeFollowup::query()->firstOrCreate(['publication_id' => $publicationId, 'days' => $days], [
                    'baseline_id' => $baseline->id, 'site_page_id' => $publication->site_page_id, 'verified_at' => $at,
                    'window_from' => $window->from->toDateString(), 'window_to' => $window->to->toDateString(), 'due_on' => $window->dueOn()->toDateString(),
                ]);
            }
        }));
    }

    private function missingBaseline(PagePublication $publication, CarbonImmutable $at): PageChangeBaseline
    {
        $revision = PageProposalRevision::query()->whereKey($publication->revision_id)->where('proposal_id', $publication->proposal_id)->firstOrFail();
        $periods = [];
        foreach ([14, 28] as $days) {
            $window = ObservationWindow::baseline($at, $days);
            $periods[$days] = [
                'search' => [...$this->search->capture([], $window), 'reason' => 'No baseline was recorded when this revision was approved.'],
                'purchases' => $this->purchases->summarize(null, $window->from, $window->to->addDay()),
                'all_recorded_purchases' => $this->purchases->summarize(null, $window->from, $window->to->addDay()),
            ];
        }

        return PageChangeBaseline::query()->create([
            'proposal_id' => $publication->proposal_id, 'revision_id' => $revision->id, 'site_page_id' => $publication->site_page_id,
            'source_snapshot_id' => $revision->source_snapshot_id, 'purchase_source_id' => null, 'pinned_at' => $at,
            'evidence' => ['pin_stage' => 'missing_at_approval', 'periods' => $periods, 'cohort' => [], 'comparison_candidates' => [], 'wider_search' => [], 'known_confounders' => [],
                'limitations' => ['No approval baseline was recorded. Historical evidence is unavailable; verification does not reconstruct it.']],
        ]);
    }
}
