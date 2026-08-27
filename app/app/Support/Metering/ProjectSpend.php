<?php

declare(strict_types=1);

namespace App\Support\Metering;

use App\Models\AssistantMessage;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Support\Tenancy\ProjectScope;
use DateTimeInterface;

/**
 * What a project has cost us, all of it, in one number.
 *
 * There are two places money leaves this application and they have nothing in
 * common but the bill. `pipeline_runs` holds the engine's spend — research,
 * planning, drafting, and the pictures, which are the largest line. The
 * assistant's spend lives on `assistant_messages`, because a conversation is
 * not a run: it has no graph, no steps, and no beginning that a price list
 * could be pinned to.
 *
 * Keeping them apart is right. Summing them in each caller is not, and this
 * class exists because the alternative was discovered rather than imagined:
 * the assistant went unpriced for as long as it did precisely because every
 * reader of cost knew where to find *pipeline* cost, and so nobody noticed
 * there was a second place to look.
 *
 * Every figure is micro-USD, matching `cost_micros` everywhere else.
 *
 * `acrossProjects()` throughout, with an explicit project condition. These
 * queries run from console commands and from the administrative panel, neither
 * of which has a current tenant — and {@see ProjectScope}
 * fails closed, so the scoped version of this class would report, calmly and
 * in every environment, that everything is free.
 */
final readonly class ProjectSpend
{
    private function __construct(
        public int $pipelineMicros,
        public int $assistantMicros,
    ) {}

    public static function for(Project $project, DateTimeInterface $since, ?DateTimeInterface $until = null): self
    {
        return new self(
            pipelineMicros: self::pipelines($project, $since, $until),
            assistantMicros: self::assistant($project, $since, $until),
        );
    }

    /**
     * The whole bill for a window — the figure a cost ceiling is compared to.
     */
    public static function total(Project $project, DateTimeInterface $since, ?DateTimeInterface $until = null): int
    {
        return self::for($project, $since, $until)->totalMicros();
    }

    public function totalMicros(): int
    {
        return $this->pipelineMicros + $this->assistantMicros;
    }

    /** @return array{pipeline_micros: int, assistant_micros: int, total_micros: int} */
    public function toArray(): array
    {
        return [
            'pipeline_micros' => $this->pipelineMicros,
            'assistant_micros' => $this->assistantMicros,
            'total_micros' => $this->totalMicros(),
        ];
    }

    /**
     * Steps, not runs — and the difference is the whole usefulness of this
     * class.
     *
     * `pipeline_runs.cost_micros` is written once, by
     * {@see PipelineRun::rollUpTotals()}, when a run settles. It
     * is the sum of that run's steps, so for anything finished the two agree
     * exactly. For anything *running* they do not: a `content_studio` run that
     * has already bought twenty pictures reports zero at the run level until it
     * finishes, and a run whose worker died reports zero for ever.
     *
     * A cost ceiling reading the run level would therefore be blind to precisely
     * the case it exists for — the runaway that is still running — and would
     * disagree with the metering screen, which reads steps. So this reads
     * steps, which are written as each one settles.
     */
    private static function pipelines(Project $project, DateTimeInterface $since, ?DateTimeInterface $until): int
    {
        return (int) PipelineStep::acrossProjects()
            ->where('project_id', $project->getKey())
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn ($query) => $query->where('created_at', '<', $until))
            ->sum('cost_micros');
    }

    private static function assistant(Project $project, DateTimeInterface $since, ?DateTimeInterface $until): int
    {
        return (int) AssistantMessage::acrossProjects()
            ->where('project_id', $project->getKey())
            ->where('created_at', '>=', $since)
            ->when($until !== null, fn ($query) => $query->where('created_at', '<', $until))
            ->sum('cost_micros');
    }
}
