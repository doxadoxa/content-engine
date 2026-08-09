<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Support\Seasonality\SeasonalCurve;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The seasonality §5 asks for: "одно поле и один метод, не интеграция".
 *
 * Both vendors already return the monthly curve; the point of these tests is
 * that reading it answers a planning question rather than merely storing a
 * number nobody consults.
 */
class SeasonalCurveTest extends TestCase
{
    /** A subject that really does peak in December. */
    private const array WINTER = [
        1 => 120, 2 => 90, 3 => 80, 4 => 70, 5 => 60, 6 => 55,
        7 => 50, 8 => 55, 9 => 70, 10 => 110, 11 => 260, 12 => 640,
    ];

    #[Test]
    public function a_curve_names_the_month_it_peaks_in(): void
    {
        $this->assertSame(12, SeasonalCurve::fromArray(self::WINTER)->peakMonth());
    }

    #[Test]
    public function a_subject_with_no_season_has_no_peak(): void
    {
        // Flat and empty are different facts about the vendor and the same fact
        // about the calendar: there is no month worth planning towards, and
        // answering "January" for either would put the engine on a schedule
        // nobody's demand asked for.
        $flat = array_fill_keys(range(1, 12), 400);

        $this->assertNull(SeasonalCurve::fromArray($flat)->peakMonth());
        $this->assertNull(SeasonalCurve::fromArray([])->peakMonth());
        $this->assertNull(SeasonalCurve::fromArray(null)->peakMonth());
        $this->assertTrue(SeasonalCurve::fromArray(null)->isEmpty());
    }

    #[Test]
    public function strength_separates_a_real_season_from_a_rounding_artefact(): void
    {
        $noise = array_fill_keys(range(1, 12), 400);
        $noise[3] = 404;

        $this->assertGreaterThan(3.0, SeasonalCurve::fromArray(self::WINTER)->strength());
        $this->assertLessThan(1.02, SeasonalCurve::fromArray($noise)->strength());

        // Both name March and December respectively, which is exactly why the
        // peak alone is not enough to commit six weeks of the calendar to.
        $this->assertSame(3, SeasonalCurve::fromArray($noise)->peakMonth());
    }

    #[Test]
    public function the_planning_window_is_four_to_six_weeks_before_the_peak(): void
    {
        $curve = SeasonalCurve::fromArray(self::WINTER);

        // §5: "Планирование — за 4–6 недель до пика." December peaks, so the
        // window runs from about 20 October to 3 November.
        $this->assertTrue($curve->isPlanningWindow(CarbonImmutable::parse('2026-10-25')));
        $this->assertTrue($curve->isPlanningWindow(CarbonImmutable::parse('2026-11-03')));

        // Too early to commission, and too late to be ahead of the rise — the
        // second is the one that matters, because a "seasonal" post published
        // into a season already climbing is just a late post.
        $this->assertFalse($curve->isPlanningWindow(CarbonImmutable::parse('2026-09-01')));
        $this->assertFalse($curve->isPlanningWindow(CarbonImmutable::parse('2026-11-05')));
    }

    #[Test]
    public function a_peak_already_under_way_is_next_years(): void
    {
        $curve = SeasonalCurve::fromArray(self::WINTER);

        // Inside December the next December is a year off. Answering "0 weeks"
        // would invite the planner to commission a seasonal post for a season
        // that is already happening, which is the one time it is worthless.
        $this->assertSame(50, $curve->weeksUntilPeak(CarbonImmutable::parse('2026-12-10')));
        $this->assertSame(4, $curve->weeksUntilPeak(CarbonImmutable::parse('2026-11-03')));
    }

    #[Test]
    public function a_curve_read_back_from_jsonb_is_the_curve_that_went_in(): void
    {
        // Postgres hands month keys back as strings. A curve keyed "12" sorts
        // and compares differently from one keyed 12, and the peak lookup wants
        // an int — so the string form has to survive the trip.
        $curve = SeasonalCurve::fromArray(['1' => 10, '7' => 90, '12' => 30]);

        $this->assertSame(7, $curve->peakMonth());
        $this->assertSame([1 => 10, 7 => 90, 12 => 30], $curve->toArray());
    }

    #[Test]
    public function a_half_garbage_curve_degrades_to_no_season_rather_than_failing(): void
    {
        // The column is fed by two vendor adapters written from documentation
        // and unverified on a live account, so a planning run must survive a
        // shape nobody expected.
        $curve = SeasonalCurve::fromArray([
            'january' => 500, 13 => 900, 4 => 'lots', 6 => -20, 8 => 300, 9 => 100,
        ]);

        $this->assertSame([8 => 300, 9 => 100], $curve->toArray());
        $this->assertSame(8, $curve->peakMonth());
    }

    #[Test]
    public function weeks_until_peak_is_counted_from_now_when_nothing_is_given(): void
    {
        Carbon::setTestNow('2026-11-03');

        $this->assertSame(4, SeasonalCurve::fromArray(self::WINTER)->weeksUntilPeak());
    }
}
