<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SitePage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<SitePage>
 */
class SitePageFactory extends Factory
{
    protected $model = SitePage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->sentence(4);

        return [
            'url' => 'https://example.com/journal/'.Str::slug($title),
            'title' => $title,
            'description' => $this->faker->sentence(12),
            'published_at' => now()->subMonths($this->faker->numberBetween(1, 24)),
            'is_article' => true,
            'read_at' => now(),
        ];
    }

    /** Known from the sitemap only — no title beyond the slug, no description. */
    public function unread(): static
    {
        return $this->state(fn (): array => ['description' => null, 'read_at' => null]);
    }

    /** A service or contact page: not a topic anybody covered. */
    public function notAnArticle(): static
    {
        return $this->state(fn (): array => [
            'url' => 'https://example.com/services',
            'is_article' => false,
        ]);
    }

    public function publishedAt(string $when): static
    {
        return $this->state(fn (): array => ['published_at' => Carbon::parse($when)]);
    }
}
