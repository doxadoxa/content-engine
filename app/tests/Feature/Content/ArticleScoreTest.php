<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Content\ArticleScore;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The score, and the difference between "costs points" and "do not publish".
 */
final class ArticleScoreTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_high_score_can_still_be_unpublishable(): void
    {
        // Everything present except the two things the style guide calls
        // non-negotiable. Treating every check as equal made a missing FAQ
        // weigh the same as prose that reads as machine-written, and an
        // operator reading only the number would never know which.
        $item = $this->unit(
            body: str_repeat(
                "## A heading\n\nWe delve into the robust and seamless realm of cleaning. "
                .'Moreover, it is worth noting the tapestry of options. Furthermore, we empower '
                ."you to leverage a myriad of solutions.\n\n",
                6,
            ),
        );

        $scored = app(ArticleScore::class)->for($item);

        $this->assertFalse($scored['publishable']);
        $this->assertContains('Reads as written, not generated', $scored['blocking']);
    }

    #[Test]
    public function a_check_that_only_costs_points_does_not_block(): void
    {
        $item = $this->unit(body: $this->goodBody());

        $scored = app(ArticleScore::class)->for($item);

        $failing = array_values(array_filter(
            $scored['checks'],
            static fn (array $check): bool => ! $check['ok'],
        ));

        // Things are missing — no images, no schema — and the piece is still
        // fit to go out. That is the distinction severity exists to draw.
        $this->assertNotEmpty($failing);
        $this->assertTrue($scored['publishable']);
        $this->assertSame([], $scored['blocking']);
    }

    #[Test]
    public function reading_level_is_not_claimed_for_a_language_it_cannot_measure(): void
    {
        // Ukrainian: close enough to Russian that Oborneva's constants would
        // give a plausible number, and no published adaptation to justify one.
        $item = $this->unit(body: $this->goodBody(), locale: 'uk');

        $scored = app(ArticleScore::class)->for($item);

        $reading = collect($scored['checks'])->firstWhere('key', 'readability');

        $this->assertNotNull($reading);
        $this->assertStringContainsString('not measured', $reading['detail']);
        // Passing rather than failing: an article is not worse for being
        // written in a language the formula does not cover.
        $this->assertTrue($reading['ok']);
    }

    #[Test]
    public function a_language_with_a_published_adaptation_is_measured_on_its_own_scale(): void
    {
        $item = $this->unit(body: $this->goodBody(), locale: 'pt-PT');

        $scored = app(ArticleScore::class)->for($item);

        $reading = collect($scored['checks'])->firstWhere('key', 'readability');

        $this->assertNotNull($reading);
        $this->assertStringContainsString('reading ease', $reading['detail']);
        $this->assertStringNotContainsString('not measured', $reading['detail']);

        // And the passive check is absent rather than reported as zero: the
        // test is a fact about English grammar.
        $this->assertNull(collect($scored['checks'])->firstWhere('key', 'passive_voice'));
    }

    private function goodBody(): string
    {
        return "## Where a weekly clean is the wrong call\n\n"
            .'A deep clean takes about three hours. Bathrooms take longest. We bring our own '
            .'cloths. If you have marble, say so first, because it needs a pH-neutral product '
            ."and most supermarket sprays will etch it.\n\n"
            ."> Tell us which rooms matter most. We will start there.\n\n"
            ."## What a visit covers\n\n"
            .'Most flats need one visit a week. A team of two finishes a two-bedroom in '
            ."2 hours. Ovens take 45 minutes on their own.\n";
    }

    private function unit(string $body, string $locale = 'en'): ContentItem
    {
        $project = Project::factory()->create();

        return app(CurrentProject::class)->run($project, fn (): ContentItem => ContentItem::factory()
            ->create([
                'locale' => $locale,
                'body_markdown' => $body,
                'body_html' => Str::markdown($body),
                'summary' => 'A sentence about cleaning that is the right sort of length for a meta description here.',
            ])
            ->load('assets'));
    }
}
