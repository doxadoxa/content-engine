<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BlogPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BlogPost>
 */
class BlogPostFactory extends Factory
{
    protected $model = BlogPost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim($this->faker->sentence(5), '.');

        return [
            'engine_id' => (string) Str::uuid(),
            'locale_group_id' => (string) Str::uuid(),
            'locale' => (string) config('blog.locale', 'en'),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'type' => 'article',
            'title' => $title,
            'summary' => $this->faker->sentence(16),
            'markdown' => null,
            'html' => '<p>'.$this->faker->paragraph().'</p>',
            'images' => [],
            'json_ld' => null,
            'faq_json_ld' => null,
            'author' => null,
            'published_at' => now()->subDays($this->faker->numberBetween(1, 60)),
        ];
    }

    /** Sent ahead of its date: stored, but not yet published. */
    public function scheduled(): static
    {
        return $this->state(fn (): array => ['published_at' => now()->addDay()]);
    }

    /** Another language of the same article. */
    public function translationOf(BlogPost $post, string $locale): static
    {
        return $this->state(fn (): array => [
            'locale_group_id' => $post->locale_group_id,
            'locale' => $locale,
            'published_at' => $post->published_at,
        ]);
    }

    public function withHero(): static
    {
        return $this->state(fn (): array => ['images' => [[
            'role' => 'hero',
            'url' => 'https://media.example.com/hero.jpg',
            'alt' => 'A hand-drawn map of a small town',
            'anchor' => null,
            'width' => 1600,
            'height' => 900,
        ]]]);
    }
}
