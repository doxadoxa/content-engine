<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BrandBrief;
use Database\Factories\Concerns\ResolvesProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandBrief>
 */
class BrandBriefFactory extends Factory
{
    use ResolvesProject;

    protected $model = BrandBrief::class;

    /**
     * All keyed by project id, not scalars.
     *
     * A batch created with no tenant current mints a project per row, and a
     * single shared counter would then hand project two a brief numbered v2
     * and flagged inactive — leaving it with a history it never had and no live
     * brief at all.
     *
     * @var array<string, int>
     */
    private array $issued = [];

    /** @var array<string, int> */
    private array $baseVersion = [];

    /** @var array<string, bool> */
    private array $hadActive = [];

    /**
     * `version` and `is_active` are sequenced by this instance rather than read
     * from the table, so `->count(3)` produces a version history instead of
     * three collisions.
     *
     * It has to be counted in PHP: `create()` expands the attributes of every
     * row in the batch before it saves any of them, so a `max(version)` query
     * inside the definition returns the same answer three times.
     *
     * The *first* row created is the active one, not the last — the factory
     * fills a history in, it does not re-order it. When the newest version has
     * to be the live one, use {@see BrandBrief::revise()}, which is the method
     * that owns that rule.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => fn (): mixed => $this->resolveProject(),
            'version' => function (array $attributes): int {
                $project = $this->prime($attributes);
                $this->issued[$project]++;

                return $this->baseVersion[$project] + $this->issued[$project];
            },
            // Declared after `version`, and depends on it having run: attribute
            // closures are evaluated in declaration order.
            'is_active' => function (array $attributes): bool {
                $project = (string) $attributes['project_id'];

                return ! $this->hadActive[$project] && $this->issued[$project] === 1;
            },
            'positioning' => fake()->sentence(12),
            'audience' => fake()->sentence(10),
            'tone' => fake()->sentence(8),
            'visual_language' => fake()->sentence(8),
            'forbidden_topics' => [fake()->word(), fake()->word()],
            'examples_liked' => [fake()->sentence(6)],
            'examples_disliked' => [fake()->sentence(6)],
            'competitors' => [fake()->domainName()],
            'change_note' => null,
        ];
    }

    /**
     * A superseded version. Use with an explicit `version`, since the pair
     * (project, version) is unique.
     */
    public function superseded(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Empty rather than random — the case that proves compileToPrompt() drops
     * sections instead of emitting empty headings.
     */
    public function blank(): static
    {
        return $this->state(fn (): array => [
            'positioning' => '',
            'audience' => '',
            'tone' => '',
            'visual_language' => '',
            'forbidden_topics' => [],
            'examples_liked' => [],
            'examples_disliked' => [],
            'competitors' => [],
        ]);
    }

    /**
     * Read a project's existing history once, the first time this batch touches
     * it. Returns the project id, which is what the caller needs next anyway.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function prime(array $attributes): string
    {
        $projectId = (string) $attributes['project_id'];

        if (array_key_exists($projectId, $this->issued)) {
            return $projectId;
        }

        $this->issued[$projectId] = 0;

        $this->baseVersion[$projectId] = (int) BrandBrief::acrossProjects()
            ->where('project_id', $projectId)
            ->max('version');

        $this->hadActive[$projectId] = BrandBrief::acrossProjects()
            ->where('project_id', $projectId)
            ->where('is_active', true)
            ->exists();

        return $projectId;
    }
}
