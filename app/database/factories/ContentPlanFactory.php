<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentPlanStatus;
use App\Models\ContentPlan;
use Database\Factories\Concerns\ResolvesProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ContentPlan>
 */
class ContentPlanFactory extends Factory
{
    use ResolvesProject;

    protected $model = ContentPlan::class;

    /**
     * Keyed by project id, for the reason spelled out in
     * {@see BrandBriefFactory}: a batch with no tenant current mints a project
     * per row, and a shared counter would start the second project's calendar
     * in a month it has no reason to start in.
     *
     * @var array<string, int>
     */
    private array $issued = [];

    /** @var array<string, Carbon> */
    private array $baseMonth = [];

    /**
     * The month walks forward with each row, so `->count(3)` is three
     * consecutive months rather than three collisions against
     * `(project_id, month)`.
     *
     * Counted in PHP for the reason given in {@see BrandBriefFactory}: the
     * whole batch is expanded before any of it is saved.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => fn (): mixed => $this->resolveProject(),
            'month' => function (array $attributes): Carbon {
                $project = (string) $attributes['project_id'];

                if (! array_key_exists($project, $this->baseMonth)) {
                    $this->baseMonth[$project] = $this->firstFreeMonth($project);
                    $this->issued[$project] = 0;
                }

                return $this->baseMonth[$project]->copy()
                    ->addMonths($this->issued[$project]++);
            },
            'status' => ContentPlanStatus::Draft,
            'approved_at' => null,
        ];
    }

    public function forMonth(Carbon|string $month): static
    {
        return $this->state(fn (): array => [
            'month' => Carbon::parse($month)->startOfMonth(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => ContentPlanStatus::Approved,
            'approved_at' => Carbon::now(),
        ]);
    }

    /**
     * This month, or the one after the project's latest plan — so a factory
     * call in a test that already made plans does not land on top of them.
     */
    private function firstFreeMonth(string $projectId): Carbon
    {
        $latest = ContentPlan::acrossProjects()
            ->where('project_id', $projectId)
            ->max('month');

        if ($latest === null) {
            return Carbon::now()->startOfMonth();
        }

        return Carbon::parse((string) $latest)->startOfMonth()->addMonth();
    }
}
