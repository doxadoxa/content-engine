<?php

declare(strict_types=1);

namespace App\FactMaintenance;

use App\Models\BusinessFactVersion;
use App\Models\FactMaintenanceCheck;
use App\Models\FactMaintenanceClaim;
use App\Models\FactMaintenanceReview;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Opportunities\RecordClaimOpportunity;
use App\Proposals\PageBlocks;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReviewFactClaim
{
    public function __construct(private readonly CurrentProject $current, private readonly FactMaintenance $maintenance, private readonly MaintenanceSources $sources) {}

    /** @param array<string,mixed> $input */
    public function review(FactMaintenanceClaim $claim, User $actor, array $input): FactMaintenanceReview
    {
        $project = $this->current->get() ?? abort(404);
        abort_unless($claim->project_id === $project->id, 404);
        abort_unless($actor->projects()->whereKey($project->id)->wherePivot('role', 'owner')->exists(), 403);
        $action = $input['action'] ?? null;
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'expected_review_id' => ['nullable', 'ulid'],
            'action' => ['required', Rule::in(['dismiss', 'acknowledge', 'reopen', 'correct', 'handoff'])], 'reason' => ['required', 'string', 'max:2000'],
            'confirm' => [Rule::excludeIf(! in_array($action, ['acknowledge', 'correct', 'handoff'], true)), 'required', 'accepted'],
            'fact_version_id' => [Rule::requiredIf(in_array($action, ['correct', 'handoff'], true)), 'nullable', 'ulid'],
            'replacement_text' => [Rule::requiredIf($action === 'handoff'), 'nullable', 'string', 'max:4000'],
            'instructions' => [Rule::requiredIf($action === 'handoff'), 'nullable', 'string', 'max:2000']])->validate();
        $hash = hash('sha256', json_encode([$claim->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($project, $actor, $claim, $data, $hash): FactMaintenanceReview {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $existing = FactMaintenanceReview::query()->where('request_key', $data['request_key'])->first();
            if ($existing !== null) {
                abort_unless(($existing->evidence['request_hash'] ?? null) === $hash, 409, 'This review identity belongs to a different action.');

                return $existing;
            }
            $claim = FactMaintenanceClaim::query()->findOrFail($claim->id);
            $latest = FactMaintenanceReview::query()->where('claim_id', $claim->id)->orderByDesc('id')->first();
            abort_unless($latest?->id === ($data['expected_review_id'] ?? null), 409, 'This finding was reviewed in another request. Reload its review history.');
            abort_if($latest?->action === 'dismiss' && $data['action'] !== 'reopen', 409, 'Explicitly reopen this dismissed finding before another action.');
            abort_if($data['action'] === 'reopen' && $latest?->action !== 'dismiss', 409, 'Only a dismissed finding can be reopened.');
            $evidence = ['request_hash' => $hash, 'snapshot_id' => $claim->source_snapshot_id, 'exact_quote' => $claim->exact_quote,
                'source' => $claim->source, 'original_relation' => $claim->relation, 'original_fact_version_id' => $claim->fact_version_id];
            if (in_array($data['action'], ['acknowledge', 'correct', 'handoff'], true)) {
                $check = FactMaintenanceCheck::query()->findOrFail($claim->check_id);
                $page = SitePage::query()->whereKey($claim->site_page_id)->lockForUpdate()->firstOrFail();
                $snapshot = PageSnapshot::query()->findOrFail($claim->source_snapshot_id);
                abort_unless(in_array($check->status, ['complete', 'partial'], true), 409, 'This check cannot support a current action. Recheck the page.');
                abort_if(($reason = $this->maintenance->currentReason($check)) !== null, 409, $reason ?? 'Current evidence is required.');
                $source = $this->sources->from($snapshot, $check->specification['business_name']);
                $text = $source['surfaces'][$claim->source['section_key']]['text'] ?? '';
                abort_unless(mb_substr($text, $claim->start_codepoint, $claim->end_codepoint - $claim->start_codepoint) === $claim->exact_quote, 409, 'The exact saved occurrence cannot be verified.');
                if (in_array($data['action'], ['correct', 'handoff'], true)) {
                    abort_unless($this->maintenance->factsCurrent([$data['fact_version_id']]), 409, 'A correction requires a current confirmed replacement fact. A retraction alone does not supply new wording.');
                    $fact = BusinessFactVersion::query()->whereKey($data['fact_version_id'])->firstOrFail();
                    $evidence['replacement_fact'] = ['id' => $fact->id, 'statement' => $fact->statement, 'source_url' => $fact->source_url, 'source_note' => $fact->source_note, 'confirmed_at' => $fact->confirmed_at?->toIso8601String()];
                    if ($data['action'] === 'correct') {
                        abort_unless($this->proposalSupported($claim, $snapshot), 422, 'This surface needs the explicit assisted handoff with reviewed replacement text and editing instructions.');
                        $opportunity = app(RecordClaimOpportunity::class)->record($actor, $page, $snapshot, $fact, 'fact_maintenance', $claim->id,
                            ['owned_page_quote' => $claim->exact_quote, 'issue' => 'Review the outdated or unsupported statement on this page.', 'reason' => $data['reason'],
                                'maintenance_check_id' => $check->id, 'claim_id' => $claim->id, 'source_surface' => $claim->source, 'original_fact_version_id' => $claim->fact_version_id]);
                        $evidence['opportunity_id'] = $opportunity->id;
                    } else {
                        $evidence['handoff'] = ['url' => $page->canonical_url, 'locale' => $page->locale, 'surface' => $claim->source,
                            'before' => $claim->exact_quote, 'after' => $data['replacement_text'], 'instructions' => $data['instructions'],
                            'preserve' => 'Change only the reviewed occurrence. Preserve unrelated text, links, forms, layout, URLs and other metadata. Do not infer replacements for retracted facts.',
                            'verification' => 'After the site editor applies the change, start a fresh page check. Export is not evidence of application or verification.'];
                    }
                }
            }

            return FactMaintenanceReview::query()->create(['claim_id' => $claim->id, 'request_key' => $data['request_key'], 'action' => $data['action'],
                'actor_id' => $actor->id, 'reason' => $data['reason'], 'evidence' => $evidence]);
        });
    }

    public function proposalSupported(FactMaintenanceClaim $claim, PageSnapshot $snapshot): bool
    {
        if (mb_strlen($claim->exact_quote) > 2400 || $claim->source['kind'] === 'json_ld') {
            return false;
        }
        if (in_array($claim->source['kind'], ['title', 'meta_description'], true)) {
            return true;
        }

        return collect(app(PageBlocks::class)->from($snapshot))->contains(fn (array $block): bool => str_contains($block['text'], PageBlocks::normalize($claim->exact_quote)));
    }
}
