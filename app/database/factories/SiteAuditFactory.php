<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SiteAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteAudit>
 */
class SiteAuditFactory extends Factory
{
    protected $model = SiteAudit::class;

    /**
     * A finished sweep, because that is the state almost every test is about —
     * an unfinished one is {@see running()} and has to say so.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'started_at' => now()->subMinutes(6),
            'finished_at' => now(),
            'health_score' => 88,
            'seo_score' => 84,
            'geo_score' => 92,
            'speed_score' => 90,
            'pages_crawled' => 12,
            'issues_found' => 4,
            'site_checks' => [],
        ];
    }

    /** A sweep in flight: crawled nothing yet and scored nothing at all. */
    public function running(): static
    {
        return $this->state(fn (): array => [
            'started_at' => now(),
            'finished_at' => null,
            'health_score' => null,
            'seo_score' => null,
            'geo_score' => null,
            'speed_score' => null,
            'pages_crawled' => 0,
            'issues_found' => 0,
        ]);
    }

    /** Finished, with no PageSpeed key anywhere near it. */
    public function withoutSpeed(): static
    {
        return $this->state(fn (): array => ['speed_score' => null]);
    }
}
