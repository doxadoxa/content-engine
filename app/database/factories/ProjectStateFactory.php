<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProjectState;
use Carbon\CarbonInterface;
use Database\Factories\Concerns\ResolvesProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ProjectState>
 */
class ProjectStateFactory extends Factory
{
    use ResolvesProject;

    protected $model = ProjectState::class;

    /** Days handed out by this instance, so `count(n)` is n consecutive days. */
    private int $issued = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $impressions = fake()->numberBetween(200, 20_000);

        return [
            'project_id' => fn (): mixed => $this->resolveProject(),
            // Consecutive days: (project_id, captured_on) is unique, and a
            // fixed date would collide on the second row. {@see daysBack()}
            // for why counting rows is not enough on its own.
            'captured_on' => fn (array $attributes): Carbon => Carbon::today()
                ->subDays($this->daysBack((string) $attributes['project_id'])),
            'brand_impressions' => fake()->numberBetween(0, 500),
            'brand_clicks' => fake()->numberBetween(0, 80),
            'brand_queries' => [fake()->word() => fake()->numberBetween(1, 50)],
            'direct_sessions' => fake()->numberBetween(0, 400),
            'referral_sessions' => fake()->numberBetween(0, 200),
            'social_sessions' => fake()->numberBetween(0, 150),
            'social_engaged_sessions' => fake()->numberBetween(0, 100),
            'social_engagement_seconds' => fake()->numberBetween(0, 40_000),
            'conversions' => fake()->numberBetween(0, 20),
            'followers' => fake()->numberBetween(0, 5_000),
            'post_impressions' => $impressions,
            'post_replies' => fake()->numberBetween(0, (int) round($impressions / 100)),
            'post_likes' => fake()->numberBetween(0, 400),
            'post_reposts' => fake()->numberBetween(0, 60),
            'post_quotes' => fake()->numberBetween(0, 20),
            'posts_published' => fake()->numberBetween(0, 2),
            'replies_sent' => fake()->numberBetween(0, 10),
            'entity_coverage' => [],
            'raw' => [],
        ];
    }

    public function on(CarbonInterface|string $date): static
    {
        return $this->state(fn (): array => [
            'captured_on' => $date instanceof CarbonInterface
                ? Carbon::instance($date)->startOfDay()
                : Carbon::parse($date)->startOfDay(),
        ]);
    }

    /**
     * How many days back the next row should be dated.
     *
     * Two counters rather than one, because the two ways of asking for three
     * rows resolve their dates at opposite moments. `count(3)->create()` builds
     * all three models before storing any, so the database still says zero for
     * each and only the instance counter separates them. `createMany(3)` stores
     * each row before building the next — but it goes through `state()`, which
     * returns a *new* factory instance, so the instance counter is back at zero
     * every time and only the stored rows separate them. Either alone collides
     * on (project_id, captured_on), which is the one index this table has.
     */
    private function daysBack(string $projectId): int
    {
        $stored = ProjectState::acrossProjects()
            ->where('project_id', $projectId)
            ->count();

        return $stored + $this->issued++;
    }
}
