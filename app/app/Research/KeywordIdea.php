<?php

declare(strict_types=1);

namespace App\Research;

use App\Support\Seasonality\SeasonalCurve;

/**
 * One keyword as the outside world reports it (§4.1).
 *
 * Deliberately not a model. This is what a source hands over — the decision
 * about whether it becomes a content unit belongs to the research pipeline,
 * which knows what the project has already written about.
 *
 * `$language` is the one field here that is about us rather than about the
 * keyword, and it earns its place: a keyword lives in a language, and the
 * article written for it has to be in that language or it cannot rank for it.
 * Ideas were stamped with the project's default locale instead, which produced
 * a Portuguese search query with an English article under it.
 */
final readonly class KeywordIdea
{
    /**
     * @param  int  $difficulty  0–100, higher is harder to rank for
     * @param  int  $volume  searches per month in the requested market
     * @param  list<string>  $entities  things the query is about
     * @param  string|null  $language  the language this keyword was measured in
     * @param  array<int, int>  $volumeByMonth  calendar month (1–12) => searches,
     *                                          empty when the vendor said nothing
     */
    public function __construct(
        public string $keyword,
        public int $volume,
        public int $difficulty,
        public ?string $parentTopic = null,
        public array $entities = [],
        public ?string $language = null,
        public array $volumeByMonth = [],
    ) {}

    /**
     * A crude score for ordering a pool: reach against effort.
     *
     * Deliberately crude and deliberately here rather than in a step, so the
     * planner and any future ranker argue with one definition. Difficulty is
     * softened by +10 so a difficulty-0 keyword does not divide by nothing and
     * dominate every list.
     */
    public function opportunity(): float
    {
        return $this->volume / ($this->difficulty + 10);
    }

    /**
     * The demand curve as something that can answer questions (§5).
     *
     * The one method the spec asks for. It lives on {@see SeasonalCurve} rather
     * than here because the same curve is asked the same things once it has
     * been stored on a unit, and two copies of "which month is the peak" would
     * drift the first time one of them learned about flat curves.
     */
    public function seasonality(): SeasonalCurve
    {
        return SeasonalCurve::fromArray($this->volumeByMonth);
    }
}
