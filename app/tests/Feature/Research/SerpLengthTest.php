<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Research\Contracts\KeywordSource;
use App\Research\FakeKeywordSource;
use App\Research\SerpLength;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How long an article should be, read off what already ranks.
 *
 * A fixed target chosen once in a wizard is a guess applied to every topic a
 * project ever writes, and the pages winning a search are the only opinion on
 * the matter worth having.
 */
final class SerpLengthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_target_comes_from_the_pages_that_rank(): void
    {
        $this->ranking([
            'https://a.test' => 800,
            'https://b.test' => 1000,
            'https://c.test' => 1200,
            'https://d.test' => 2000,
        ]);

        // Median 1100, 75th percentile 1400. The rule takes the higher of the
        // percentile and median × 1.2, so comfortably above the middle without
        // chasing the 2000-word outlier.
        $this->assertSame(1400, app(SerpLength::class)->targetFor('cleaning lisbon', 'pt'));
    }

    #[Test]
    public function a_serp_too_thin_to_measure_gives_no_answer(): void
    {
        $this->ranking(['https://a.test' => 900, 'https://b.test' => 1100]);

        // Two pages is not a distribution. Null rather than a default, so the
        // caller falls back to the project's own setting and knows it did — a
        // silent default here would look exactly like a measurement.
        $this->assertNull(app(SerpLength::class)->targetFor('cleaning lisbon', 'pt'));
    }

    #[Test]
    public function pages_that_cannot_be_read_are_skipped_rather_than_counted(): void
    {
        $this->ranking([
            'https://a.test' => 900,
            'https://b.test' => 1100,
            'https://c.test' => 1300,
        ], failing: ['https://d.test', 'https://e.test']);

        $this->assertNotNull(app(SerpLength::class)->targetFor('cleaning lisbon', 'pt'));
    }

    #[Test]
    public function scripts_are_not_counted_as_prose(): void
    {
        /** @var FakeKeywordSource $keywords */
        $keywords = app(KeywordSource::class);

        $keywords->willRank(array_map(
            static fn (string $url): array => ['url' => $url, 'title' => 'A page'],
            ['https://a.test', 'https://b.test', 'https://c.test'],
        ));

        // Three hundred real words wrapped around a large inline script. Left
        // in, every result looks enormous and the target follows it.
        $script = '<script>'.str_repeat('var x = 1; ', 2000).'</script>';
        $prose = '<p>'.str_repeat('word ', 300).'</p>';

        Http::fake(['*' => Http::response("<html><body>{$script}{$prose}</body></html>")]);

        $target = app(SerpLength::class)->targetFor('cleaning lisbon', 'pt');

        $this->assertNotNull($target);
        $this->assertLessThan(500, $target);
    }

    /**
     * @param  array<string, int>  $pages  url => word count
     * @param  list<string>  $failing
     */
    private function ranking(array $pages, array $failing = []): void
    {
        /** @var FakeKeywordSource $keywords */
        $keywords = app(KeywordSource::class);

        $keywords->willRank(array_map(
            static fn (string $url): array => ['url' => $url, 'title' => 'A page'],
            [...array_keys($pages), ...$failing],
        ));

        $stubs = [];

        foreach ($pages as $url => $words) {
            $stubs[parse_url($url, PHP_URL_HOST).'*'] = Http::response(
                '<html><body><p>'.str_repeat('word ', $words).'</p></body></html>',
            );
        }

        foreach ($failing as $url) {
            $stubs[parse_url($url, PHP_URL_HOST).'*'] = Http::response('', 500);
        }

        Http::fake($stubs);
    }
}
