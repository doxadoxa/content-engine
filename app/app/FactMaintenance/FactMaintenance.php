<?php

declare(strict_types=1);

namespace App\FactMaintenance;

use App\Ai\Contracts\ModelSession;
use App\Billing\Entitlements;
use App\Facts\AssessFactClaims;
use App\Facts\FactFinding;
use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\FactMaintenanceCheck;
use App\Models\FactMaintenanceClaim;
use App\Models\FactMaintenanceResult;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Pages\TrackedPages;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class FactMaintenance
{
    public function __construct(private readonly CurrentProject $current, private readonly MaintenanceSources $sources, private readonly AssessFactClaims $checker) {}

    /** @param array<string,mixed> $input
     * @return list<FactMaintenanceCheck>
     */
    public function start(Project $project, User $actor, array $input): array
    {
        abort_unless($actor->projects()->whereKey($project->id)->wherePivot('role', 'owner')->exists(), 403);
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'page_ids' => ['required', 'array', 'min:1', 'max:20'],
            'page_ids.*' => ['required', 'ulid', 'distinct'], 'fact_version_ids' => ['required', 'array', 'min:1', 'max:20'],
            'fact_version_ids.*' => ['required', 'ulid', 'distinct']])->validate();

        return $this->current->run($project, fn (): array => DB::transaction(function () use ($project, $actor, $data): array {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $ids = $data['fact_version_ids'];
            sort($ids);
            $existing = FactMaintenanceCheck::query()->where('request_key', $data['request_key'])->get();
            if ($existing->isNotEmpty()) {
                $pages = $data['page_ids'];
                sort($pages);
                abort_unless($existing->pluck('site_page_id')->sort()->values()->all() === $pages && $existing->every(fn (FactMaintenanceCheck $check): bool => $check->specification['fact_version_ids'] === $ids), 409, 'This request identity belongs to another check. Start a new explicit check.');

                return array_values($existing->all());
            }
            if (($reason = $this->generationRefusal($project)) !== null) {
                throw ValidationException::withMessages(['check' => $reason]);
            }
            abort_unless($this->factsCurrent($ids), 409, 'Select current confirmed facts that are still within their review dates.');
            $pages = SitePage::query()->tracked()->whereIn('id', $data['page_ids'])->lockForUpdate()->get();
            abort_unless($pages->count() === count($data['page_ids']), 404);
            $checks = [];
            foreach ($pages as $page) {
                $checks[] = FactMaintenanceCheck::query()->create(['site_page_id' => $page->id, 'request_key' => $data['request_key'], 'requested_by' => $actor->id,
                    'specification' => ['canonical_url' => $page->canonical_url, 'locale' => $page->locale, 'tracked_at' => $page->tracked_at?->toIso8601String(),
                        'fact_version_ids' => $ids, 'business_name' => $project->name], 'status' => 'queued']);
            }
            DB::afterCommit(fn () => $this->dispatch($project));

            return $checks;
        }));
    }

    public function dispatch(Project $project): void
    {
        $this->current->run($project, fn () => DB::transaction(function () use ($project): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            foreach (FactMaintenanceCheck::query()->where('status', 'queued')->whereNull('pipeline_run_id')->get() as $check) {
                $run = app(PipelineRunner::class)->start('fact_maintenance', $project, ['check_id' => $check->id]);
                $check->update(['pipeline_run_id' => $run->id]);
            }
        }));
    }

    public function perform(FactMaintenanceCheck $check, ModelSession $session): void
    {
        $project = $this->current->get() ?? abort(404);
        abort_unless($check->project_id === $project->id, 404);
        $claimed = DB::transaction(function () use ($project, $check): bool {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $check = FactMaintenanceCheck::query()->lockForUpdate()->findOrFail($check->id);
            if ($check->status !== 'queued') {
                return false;
            }
            if (($reason = $this->generationRefusal($project)) !== null) {
                $check->update(['status' => 'unavailable', 'reason' => $reason, 'finished_at' => now()]);

                return false;
            }
            $reason = $this->currentReason($check, requireSnapshot: false);
            if ($reason !== null || ! User::query()->find($check->requested_by)?->projects()->whereKey($project->id)->wherePivot('role', 'owner')->exists()) {
                $check->update(['status' => 'stale', 'reason' => $reason ?? 'The requesting owner no longer has permission.', 'finished_at' => now()]);

                return false;
            }
            $check->update(['status' => 'running', 'attempted_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return;
        }
        try {
            $page = SitePage::query()->findOrFail($check->site_page_id);
            $captured = app(TrackedPages::class)->capture($project, $page);
            $snapshot = $captured->latestSnapshot;
            abort_unless($snapshot !== null, 409);
            $check->update(['source_snapshot_id' => $snapshot->id]);
            $check->refresh();
            $reason = $this->currentReason($check);
            if ($reason !== null) {
                $check->update(['status' => 'stale', 'reason' => $reason, 'finished_at' => now()]);

                return;
            }
            $source = $this->sources->from($snapshot, $check->specification['business_name']);
            $facts = array_values(BusinessFactVersion::query()->whereIn('id', $check->specification['fact_version_ids'])->get()->all());
            $assessment = $this->checker->assess($session, $source['sections'], $facts);
            DB::transaction(function () use ($project, $check, $snapshot, $source, $assessment): void {
                Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                $check = FactMaintenanceCheck::query()->lockForUpdate()->findOrFail($check->id);
                SitePage::query()->whereKey($check->site_page_id)->lockForUpdate()->firstOrFail();
                if (FactMaintenanceResult::query()->where('check_id', $check->id)->exists()) {
                    return;
                }
                $reason = $this->currentReason($check);
                $status = $reason !== null ? 'stale' : $assessment->status;
                if ($status === 'complete' && $source['coverage']['capture_status'] !== 'captured') {
                    $status = 'partial';
                }
                FactMaintenanceResult::query()->create(['check_id' => $check->id, 'assessment' => $assessment->toArray(), 'source_coverage' => $source['coverage'],
                    'comparisons' => $this->comparisons($check, $source['surfaces'], $assessment->findings)]);
                foreach ($assessment->findings as $finding) {
                    $surface = $source['surfaces'][$finding->sectionKey];
                    unset($surface['text']);
                    FactMaintenanceClaim::query()->create(['check_id' => $check->id, 'site_page_id' => $check->site_page_id, 'source_snapshot_id' => $snapshot->id,
                        'source' => [...$surface, 'section_key' => $finding->sectionKey, 'canonical_url' => $check->specification['canonical_url'], 'locale' => $check->specification['locale']],
                        'exact_quote' => $finding->exactQuote, 'start_codepoint' => $finding->startCodepoint, 'end_codepoint' => $finding->endCodepoint,
                        'relation' => $finding->relation, 'fact_version_id' => $finding->factVersionId, 'reason' => $finding->reason, 'reference_ids' => $finding->referenceIds]);
                }
                $check->update(['status' => $status, 'reason' => $reason, 'finished_at' => now()]);
            });
        } catch (Throwable) {
            FactMaintenanceCheck::query()->whereKey($check->id)->where('status', 'running')->update(['status' => 'indeterminate',
                'reason' => 'This check did not record a complete outcome. Some model work may have incurred cost. Start a new explicit check; this attempt will not be automatically repeated.', 'finished_at' => now()]);
        }
    }

    /** @param list<string> $ids */
    public function factsCurrent(array $ids): bool
    {
        $facts = BusinessFactVersion::query()->whereIn('id', $ids)->get();
        $currentIds = BusinessFact::query()->whereIn('current_version_id', $ids)->pluck('current_version_id')->all();

        return $facts->count() === count($ids) && count($currentIds) === count($ids) && $facts->every(fn (BusinessFactVersion $fact): bool => $fact->isUsable());
    }

    public function currentReason(FactMaintenanceCheck $check, bool $requireSnapshot = true): ?string
    {
        $page = SitePage::query()->find($check->site_page_id);
        if ($page === null || $page->tracked_at === null || $page->canonical_url !== $check->specification['canonical_url'] || $page->locale !== $check->specification['locale']
            || $page->tracked_at->toIso8601String() !== $check->specification['tracked_at']) {
            return 'Tracking, page identity or language changed. Start a check of the current page.';
        }
        if (! $this->factsCurrent($check->specification['fact_version_ids'])) {
            return 'A selected fact changed, was retracted or needs renewed confirmation. Select current evidence and recheck.';
        }
        if ($requireSnapshot && ($page->latestSnapshot?->id !== $check->source_snapshot_id || $page->latestSnapshot?->captured_at->lessThan(now()->subDays(30)))) {
            return 'The public snapshot changed or expired. Check the current page before acting.';
        }

        return null;
    }

    private function generationRefusal(Project $project): ?string
    {
        $entitlements = app(Entitlements::class);
        $entitlements->forget($project);

        return $entitlements->for($project)->refusal()?->message;
    }

    /** @param array<string,array<string,mixed>> $surfaces
     * @param  list<FactFinding>  $findings
     * @return list<array<string,mixed>>
     */
    private function comparisons(FactMaintenanceCheck $check, array $surfaces, array $findings): array
    {
        $previous = FactMaintenanceCheck::query()->where('site_page_id', $check->site_page_id)->where('id', '<', $check->id)
            ->where('specification->canonical_url', $check->specification['canonical_url'])->where('specification->locale', $check->specification['locale'])
            ->whereIn('id', FactMaintenanceClaim::query()->select('check_id'))->orderByDesc('id')->first();
        if ($previous === null) {
            return [];
        }

        $versions = BusinessFactVersion::query()->whereIn('id', array_filter([...array_column($findings, 'factVersionId'), ...FactMaintenanceClaim::query()->where('check_id', $previous->id)->pluck('fact_version_id')->all()]))->pluck('business_fact_id', 'id');

        return array_values(FactMaintenanceClaim::query()->where('check_id', $previous->id)->get()->map(function (FactMaintenanceClaim $claim) use ($surfaces, $findings, $versions): array {
            $surface = $surfaces[$claim->source['section_key']] ?? null;
            $complete = $surface !== null && mb_strlen($surface['text']) === $surface['total_characters'];
            $found = $surface !== null && str_contains($surface['text'], $claim->exact_quote);
            $changed = array_values(array_filter($findings, fn (FactFinding $finding): bool => $claim->fact_version_id !== null && $finding->factVersionId !== null
                && $versions->get($claim->fact_version_id) === $versions->get($finding->factVersionId) && $finding->sectionKey === $claim->source['section_key'] && $finding->exactQuote !== $claim->exact_quote));

            return ['previous_claim_id' => $claim->id, 'exact_quote' => $claim->exact_quote, 'previous_fact_version_id' => $claim->fact_version_id,
                'observation' => $found ? 'remaining' : ($changed !== [] ? 'changed_claim_candidate' : ($complete ? 'quote_removed' : 'unknown')),
                'current_candidates' => array_map(fn (FactFinding $finding): array => $finding->toArray(), $changed),
                'meaning' => $found ? 'The exact earlier quote remains; review its current assessment.' : ($changed !== []
                    ? 'The current checker found different wording tied to the same business fact. Review the quoted candidate below before deciding that the claim changed or was corrected.'
                    : 'The exact quote was not observed in this surface. Its wording may have been removed or rephrased; this does not establish that the business fact was corrected.')];
        })->all());
    }
}
