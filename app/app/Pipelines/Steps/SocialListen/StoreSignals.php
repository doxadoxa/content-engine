<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Enums\SignalKind;
use App\Models\Concerns\BelongsToProject;
use App\Models\Signal;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Write the surviving candidates (§3, §4.1).
 *
 * **`upsert()` does not work here, and the migration says why.**
 * `signals_external_id_unique` is a *partial* unique index — `(project_id,
 * source, external_id) WHERE external_id IS NOT NULL` — and Postgres matches a
 * partial index only when the statement restates its predicate. Laravel's
 * `upsert()` emits `ON CONFLICT (columns)` with no `WHERE`, so it fails with
 * SQLSTATE 42P10, "there is no unique or exclusion constraint matching the ON
 * CONFLICT specification". The migration spells out the shape that does work
 * and this is it, repeated predicate and all.
 *
 * The upsert matters rather than being a nicety, and what it guards is the
 * race. {@see Deduplicate} has already dropped anything the project holds an
 * open signal for, including by the platform's own id, so in the ordinary hour
 * every row here is new. What is left is two workers on one hour — a resumed
 * run beside a re-dispatched step — and for that the choice is between an
 * update and a unique violation that aborts the surrounding transaction and
 * every query after it. The webhook receiver reaches the same conclusion for
 * the same reason and reaches for `insertOrIgnore`.
 *
 * **Rows with no external id take the other path.** They are outside the index
 * by construction, so the platform's uniqueness does not apply and the
 * fingerprint is the only identity they have — which is what §4.1 asks for
 * anyway. {@see Deduplicate} has already compared them against the open table,
 * so what is left here is the narrow race of two workers on the same hour, and
 * the guard is a re-read of the fingerprint immediately before the insert.
 *
 * `project_id` is written explicitly. This goes through the query builder, so
 * the `creating` hook that {@see BelongsToProject} stamps
 * the tenant with never fires — and the column is not nullable.
 */
class StoreSignals extends AbstractStep
{
    public static function key(): string
    {
        return 'store_signals';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [Deduplicate::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(Deduplicate::key())) {
            return StepResult::skip('Nothing survived to be stored.');
        }

        $candidates = $context->output(Deduplicate::key(), CandidatePayload::class)->candidates;

        if ($candidates === []) {
            return StepResult::success(new StoredSignalsPayload([], []));
        }

        $projectId = (string) $context->project->getKey();
        $now = CarbonImmutable::now()->utc();

        $ids = [];
        $questions = [];

        foreach ($candidates as $candidate) {
            $id = $candidate->externalId === null
                ? $this->insertByFingerprint($projectId, $candidate, $now)
                : $this->upsertByExternalId($projectId, $candidate, $now);

            if ($id === null) {
                continue;
            }

            $ids[] = $id;

            if ($candidate->kind === SignalKind::Question) {
                $questions[] = $id;
            }
        }

        $context->remember('social_listen.stored', count($ids));
        $context->remember('social_listen.questions', count($questions));

        return StepResult::success(new StoredSignalsPayload($ids, $questions));
    }

    /**
     * The path the migration's docblock prescribes.
     *
     * `RETURNING id` rather than a follow-up select, so the row's id is the
     * one the statement settled on whether it inserted or updated — which is
     * the only way the caller can hand a stable `signal_id` to the planner.
     */
    private function upsertByExternalId(string $projectId, CandidateSignal $candidate, CarbonImmutable $now): ?string
    {
        $row = $this->row($projectId, $candidate, $now);

        $returned = DB::selectOne(
            <<<'SQL'
                INSERT INTO signals
                    (id, project_id, kind, source, external_id, fingerprint, title, url,
                     entities, occurred_at, expires_at, weight, raw, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?::jsonb, ?, ?)
                ON CONFLICT (project_id, source, external_id) WHERE external_id IS NOT NULL
                DO UPDATE SET
                    kind = EXCLUDED.kind,
                    fingerprint = EXCLUDED.fingerprint,
                    title = EXCLUDED.title,
                    url = EXCLUDED.url,
                    entities = EXCLUDED.entities,
                    occurred_at = EXCLUDED.occurred_at,
                    expires_at = EXCLUDED.expires_at,
                    weight = EXCLUDED.weight,
                    raw = EXCLUDED.raw,
                    updated_at = EXCLUDED.updated_at
                RETURNING id
                SQL,
            array_values($row),
        );

        $id = is_object($returned) ? ($returned->id ?? null) : null;

        return is_string($id) ? $id : null;
    }

    /**
     * A source with no id of its own: keyed on the fingerprint instead (§4.1).
     */
    private function insertByFingerprint(string $projectId, CandidateSignal $candidate, CarbonImmutable $now): string
    {
        $existing = Signal::query()
            ->where('fingerprint', $candidate->fingerprint)
            ->whereNull('external_id')
            ->value('id');

        if (is_string($existing)) {
            return $existing;
        }

        $row = $this->row($projectId, $candidate, $now);

        DB::table('signals')->insert($row);

        return (string) $row['id'];
    }

    /**
     * One row, in the order both statements above bind it.
     *
     * The json columns are encoded here because the query builder does not
     * apply the model's casts.
     *
     * The instants are strings carrying their offset, and both halves of that
     * matter. Strings, because PDO cannot bind a `DateTimeInterface` and the
     * statement above is raw. Carrying the offset, because these columns are
     * `timestamptz` and Laravel's own formatter drops it — the trap
     * {@see Signal}'s docblock opens with, where Postgres then reads the digits
     * in the session's timezone and the thirty-day window of §4.1 is off by
     * the project's offset.
     *
     * @return array<string, mixed>
     */
    private function row(string $projectId, CandidateSignal $candidate, CarbonImmutable $now): array
    {
        return [
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'kind' => $candidate->kind->value,
            'source' => $candidate->source->value,
            'external_id' => $candidate->externalId,
            'fingerprint' => $candidate->fingerprint,
            'title' => $candidate->title,
            'url' => $candidate->url,
            'entities' => json_encode($candidate->entities),
            'occurred_at' => self::instant($candidate->occurredAt),
            'expires_at' => $candidate->expiresAt === null ? null : self::instant($candidate->expiresAt),
            'weight' => $candidate->weight,
            'raw' => json_encode($candidate->raw),
            'created_at' => self::instant($now),
            'updated_at' => self::instant($now),
        ];
    }

    private static function instant(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:sP');
    }
}
