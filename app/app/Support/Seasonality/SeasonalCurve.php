<?php

declare(strict_types=1);

namespace App\Support\Seasonality;

use App\Support\Duty\DutyHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A subject's demand across the calendar year (§5).
 *
 * Both keyword vendors already return a monthly curve — DataForSEO sends it
 * unasked for inside `keyword_info`, Ahrefs sends it when `volume_history` is
 * named in the select — and `KeywordIdea` threw it away. §5 calls picking it up
 * "одно поле и один метод, не интеграция", and this is the method: the field is
 * `content_items.monthly_volumes`, and everything anyone wants to ask of it is
 * here rather than in the two places that hold it.
 *
 * A curve is keyed by calendar month, 1–12, and is deliberately not keyed by
 * year. The question §5 asks is "when in the year does this peak", and a vendor
 * window that happens to straddle a new year should not make December two
 * different facts. The adapters average the duplicate months before this ever
 * sees them.
 */
final readonly class SeasonalCurve
{
    /** How far ahead §5 wants the seasonal band planned. */
    public const int PLAN_WEEKS_MIN = 4;

    public const int PLAN_WEEKS_MAX = 6;

    /**
     * @param  array<int, int>  $volumes  month (1–12) => searches
     */
    private function __construct(private array $volumes) {}

    /**
     * Read whatever the column holds.
     *
     * Tolerant on purpose, like {@see DutyHours::fromArray()}:
     * month keys come back from jsonb as strings, vendors have been known to
     * send a thirteenth month, and a curve that is half-garbage should degrade
     * to "no season" rather than fail a planning run.
     *
     * @param  array<array-key, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return new self([]);
        }

        $volumes = [];

        foreach ($raw as $month => $volume) {
            if (! is_numeric($month) || ! is_numeric($volume)) {
                continue;
            }

            $month = (int) $month;

            if ($month >= 1 && $month <= 12 && (int) $volume >= 0) {
                $volumes[$month] = (int) $volume;
            }
        }

        ksort($volumes);

        return new self($volumes);
    }

    public function isEmpty(): bool
    {
        return $this->volumes === [];
    }

    /**
     * The month this subject peaks in, or null when it has no season.
     *
     * Null rather than a guess in the two cases where a peak would be a lie:
     * no curve at all, and a flat one. A subject searched the same amount every
     * month has no peak, and answering "January" for it would put the engine on
     * a schedule nobody's demand asked for.
     */
    public function peakMonth(): ?int
    {
        if ($this->volumes === [] || count(array_unique($this->volumes)) < 2) {
            return null;
        }

        $peak = array_search(max($this->volumes), $this->volumes, true);

        return is_int($peak) ? $peak : null;
    }

    /**
     * The peak against the average month. 1.0 is flat, null is unmeasured.
     *
     * {@see peakMonth()} alone cannot tell a real December from a rounding
     * artefact, and committing six weeks of the calendar to noise is worse than
     * not planning the band at all — so the caller gets the number and decides
     * its own threshold rather than having one hidden here.
     */
    public function strength(): ?float
    {
        if ($this->volumes === []) {
            return null;
        }

        $mean = array_sum($this->volumes) / count($this->volumes);

        return $mean > 0.0 ? max($this->volumes) / $mean : null;
    }

    /**
     * Whole weeks from `$from` to the start of the next peak month.
     *
     * Measured to the first of the month rather than to some point inside it,
     * because §5 plans *ahead* of a peak: the work has to be live when demand
     * starts rising, not when it tops out. A peak in the current month counts
     * as this year's only while it is still ahead — once we are inside it, the
     * next one is a year away, and saying "0 weeks" would invite the planner to
     * commission a seasonal post for a season already under way.
     */
    public function weeksUntilPeak(?CarbonInterface $from = null): ?int
    {
        $peak = $this->peakMonth();

        if ($peak === null) {
            return null;
        }

        $from = CarbonImmutable::instance($from ?? now())->startOfDay();
        $target = $from->startOfMonth()->setMonth($peak);

        if ($target->lessThanOrEqualTo($from)) {
            $target = $target->addYear();
        }

        return (int) floor($from->diffInDays($target) / 7);
    }

    /** Whether now is the window §5 wants the seasonal band commissioned in. */
    public function isPlanningWindow(?CarbonInterface $from = null): bool
    {
        $weeks = $this->weeksUntilPeak($from);

        return $weeks !== null
            && $weeks >= self::PLAN_WEEKS_MIN
            && $weeks <= self::PLAN_WEEKS_MAX;
    }

    /** @return array<int, int> */
    public function toArray(): array
    {
        return $this->volumes;
    }
}
