<?php

declare(strict_types=1);

namespace App\Pages;

use App\FactMaintenance\FactUsageImpacts;
use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\Project;
use App\Models\User;
use App\Proposals\Proposals;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BusinessFacts
{
    public function __construct(private readonly CurrentProject $current) {}

    /** @param array<string, mixed> $input */
    public function save(Project $project, User $actor, array $input, ?BusinessFact $fact = null): BusinessFact
    {
        abort_unless($actor->projects()->whereKey($project->id)->wherePivot('role', 'owner')->exists(), 403);
        abort_if($fact !== null && $fact->project_id !== $project->id, 404);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:200'],
            'statement' => ['required', 'string', 'max:4000'],
            'source_url' => ['nullable', 'url:http,https', 'max:2000'],
            'source_note' => ['required', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['draft', 'confirmed', 'retracted'])],
            'confirm' => [Rule::excludeIf(($input['status'] ?? null) !== 'confirmed'), 'required', 'accepted'],
            'review_due_at' => [Rule::requiredIf(($input['status'] ?? null) === 'confirmed'), 'nullable', 'date', 'after:today', 'before_or_equal:'.now()->addYear()->toDateString()],
            'expected_version_id' => [$fact === null ? 'nullable' : 'required', 'nullable', 'ulid'],
        ])->validate();

        return $this->current->run($project, fn () => DB::transaction(function () use ($project, $actor, $data, $fact): BusinessFact {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($fact !== null) {
                $fact = BusinessFact::query()->whereKey($fact->id)->lockForUpdate()->firstOrFail();
                if ($fact->current_version_id !== ($data['expected_version_id'] ?? null)) {
                    throw ValidationException::withMessages(['expected_version_id' => 'This fact changed while you were reviewing it. Reload the current version before saving.']);
                }
                $fact->update(['name' => $data['name']]);
            } else {
                $fact = BusinessFact::query()->create(['name' => $data['name']]);
            }
            $confirmed = $data['status'] === 'confirmed';
            $version = BusinessFactVersion::query()->create([
                'business_fact_id' => $fact->id,
                'version' => ((int) $fact->versions()->max('version')) + 1,
                'statement' => $data['statement'], 'source_url' => $data['source_url'] ?? null,
                'source_note' => $data['source_note'], 'status' => $data['status'],
                'confirmed_by' => $confirmed ? $actor->id : null, 'confirmed_at' => $confirmed ? now() : null,
                'review_due_at' => $confirmed ? $data['review_due_at'] : null, 'created_by' => $actor->id,
            ]);
            $fact->forceFill(['current_version_id' => $version->id])->save();
            app(Proposals::class)->invalidateFact($fact);
            app(FactUsageImpacts::class)->changed($fact, $version);

            return $fact->fresh(['currentVersion']);
        }));
    }
}
