<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Project;
use App\Models\ServiceEffortEntry;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ServiceEffort
{
    public const CATEGORIES = ['onboarding', 'review', 'publication', 'correction', 'maintenance', 'support'];

    /** @param array<string, mixed> $input */
    public function record(Project $project, User $actor, array $input): ServiceEffortEntry
    {
        abort_unless($actor->projects()->whereKey($project->id)->wherePivot('role', 'owner')->exists(), 403);
        $data = Validator::make($input, [
            'request_id' => ['required', 'uuid'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'hourly_usd_cents' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'happened_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'note' => ['required', 'string', 'max:2000'],
            'supersedes_id' => ['nullable', 'ulid'],
        ])->validate();
        $values = [
            'actor_id' => $actor->id, 'request_id' => strtolower($data['request_id']),
            'category' => $data['category'], 'minutes' => (int) $data['minutes'],
            'hourly_usd_cents' => isset($data['hourly_usd_cents']) ? (int) $data['hourly_usd_cents'] : null,
            'happened_at' => Carbon::parse($data['happened_at'])->utc()->toIso8601String(),
            'note' => trim($data['note']), 'supersedes_id' => $data['supersedes_id'] ?? null,
        ];
        if (($values['minutes'] === 0 && $values['supersedes_id'] === null) || $values['note'] === '') {
            throw ValidationException::withMessages(['note' => 'Describe real work, or correct an existing entry with a reason.']);
        }
        $fingerprint = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));

        return app(CurrentProject::class)->run($project, fn () => DB::transaction(function () use ($project, $values, $fingerprint): ServiceEffortEntry {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $existing = ServiceEffortEntry::query()->where('request_id', $values['request_id'])->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'This save was already used for different work. Reload before recording another entry.');

                return $existing;
            }
            if ($values['supersedes_id'] !== null) {
                $original = ServiceEffortEntry::query()->whereKey($values['supersedes_id'])->firstOrFail();
                abort_if($original->replacement()->exists(), 409, 'This entry already has a correction. Correct its latest replacement.');
            }

            return ServiceEffortEntry::query()->create([...$values, 'fingerprint' => $fingerprint]);
        }));
    }
}
