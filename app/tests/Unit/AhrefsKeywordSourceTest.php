<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Research\AhrefsKeywordSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Ahrefs adapter's response mapping, and one thing about its requests.
 *
 * The suite binds a fake behind the keyword port, so without this the adapter
 * ships unexercised — which is how §5's Season band came to be half dead on
 * this vendor with every test passing. Ahrefs returns exactly the fields a
 * request's `select` names, so a curve that is not asked for is a curve that
 * does not exist, and {@see AhrefsKeywordSource::monthlyCurve()} answers `[]`
 * for a row that never carried one. There is no error and no empty response to
 * notice: the keyword lands in the pool looking aseasonal.
 *
 * So the requests are asserted here as well as the responses. The equivalent
 * for DataForSEO is {@see DataForSeoKeywordSourceTest} — where `monthly_searches`
 * arrives unbidden and only the parsing can be wrong.
 */
final class AhrefsKeywordSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('research.ahrefs.token', 'ahrefs-token');
        config()->set('research.ahrefs.base_url', 'https://api.ahrefs.test');
    }

    // ------------------------------------------------------ matching terms

    #[Test]
    public function matching_terms_asks_for_the_monthly_curve(): void
    {
        $this->fakeWith('matching-terms', ['keywords' => []]);

        $this->source()->matchingTerms('christmas cleaning', 'PT');

        $this->assertSelectIncludes('volume_history');
    }

    #[Test]
    public function matching_terms_keeps_the_monthly_curve(): void
    {
        $this->fakeWith('matching-terms', ['keywords' => [[
            'keyword' => 'christmas cleaning',
            'volume' => 400,
            'difficulty' => 20,
            'parent_topic' => 'deep cleaning',
            'volume_history' => [
                ['date' => '2025-12-01', 'volume' => 900],
                ['date' => '2026-01-01', 'volume' => 120],
                // The vendor's window rolls, so one calendar month can appear
                // twice.
                ['date' => '2026-12-01', 'volume' => 700],
            ],
        ]]]);

        $idea = $this->source()->matchingTerms('christmas cleaning', 'PT')[0];

        // Averaged rather than summed, exactly as the DataForSEO adapter does
        // it: a summed December reads as twice the season it is, purely because
        // of when the question was asked.
        $this->assertSame([1 => 120, 12 => 800], $idea->volumeByMonth);
        $this->assertSame(12, $idea->seasonality()->peakMonth());
    }

    // ------------------------------------------------------------- measure

    #[Test]
    public function measure_asks_for_the_monthly_curve(): void
    {
        $this->fakeWith('overview', ['keywords' => []]);

        $this->source()->measure(['christmas cleaning'], 'PT');

        // The bug this file exists for. `measure()` is the measured half of
        // research — every keyword the engine scores enters the pool through
        // it — and its select named only keyword, volume and difficulty. The
        // curve was then parsed out of a row that never had one, so
        // `GatherCandidates::seasonal()` found nothing to plan from, every week.
        $this->assertSelectIncludes('volume_history');
    }

    #[Test]
    public function measure_keeps_the_monthly_curve(): void
    {
        $this->fakeWith('overview', ['keywords' => [[
            'keyword' => 'christmas cleaning',
            'volume' => 400,
            'difficulty' => 20,
            'volume_history' => [
                ['date' => '2025-12-01', 'volume' => 900],
                ['date' => '2026-01-01', 'volume' => 120],
                ['date' => '2026-12-01', 'volume' => 700],
            ],
        ]]]);

        $idea = $this->source()->measure(['christmas cleaning'], 'PT')[0];

        $this->assertSame([1 => 120, 12 => 800], $idea->volumeByMonth);
        $this->assertSame(12, $idea->seasonality()->peakMonth());
    }

    #[Test]
    public function a_keyword_with_no_curve_simply_has_no_season(): void
    {
        $this->fakeWith('overview', ['keywords' => [[
            'keyword' => 'house cleaning lisbon',
            'volume' => 250,
            'difficulty' => 12,
        ]]]);

        $idea = $this->source()->measure(['house cleaning lisbon'], 'PT')[0];

        // Silence rather than a flat year. §5's Season band must not fire on a
        // keyword nobody measured a curve for.
        $this->assertSame([], $idea->volumeByMonth);
        $this->assertNull($idea->seasonality()->peakMonth());
    }

    #[Test]
    public function an_unreadable_date_does_not_invent_a_january(): void
    {
        $this->fakeWith('overview', ['keywords' => [[
            'keyword' => 'christmas cleaning',
            'volume' => 400,
            'volume_history' => [
                ['date' => 'not a date', 'volume' => 900],
                ['date' => '2026-07-01', 'volume' => 200],
            ],
        ]]]);

        $idea = $this->source()->measure(['christmas cleaning'], 'PT')[0];

        // `strtotime()` answers false, and falling back to the epoch would file
        // the point under January and manufacture a winter out of a parse
        // failure.
        $this->assertSame([7 => 200], $idea->volumeByMonth);
    }

    // ---------------------------------------------------------------- setup

    private function source(): AhrefsKeywordSource
    {
        return app(AhrefsKeywordSource::class);
    }

    /** @param array<string, mixed> $body */
    private function fakeWith(string $endpoint, array $body): void
    {
        Http::fake(["api.ahrefs.test/*{$endpoint}*" => Http::response($body)]);
    }

    /** What the one recorded request asked Ahrefs to return. */
    private function assertSelectIncludes(string $field): void
    {
        /** @var list<Request> $requests */
        $requests = Http::recorded()->map(static fn (array $pair): Request => $pair[0])->all();

        $this->assertCount(1, $requests);

        parse_str((string) parse_url($requests[0]->url(), PHP_URL_QUERY), $query);

        $select = is_string($query['select'] ?? null) ? $query['select'] : '';

        $this->assertContains($field, explode(',', $select), "The request did not select {$field}.");
    }
}
