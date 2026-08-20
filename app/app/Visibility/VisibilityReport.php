<?php

declare(strict_types=1);

namespace App\Visibility;

use App\Models\LlmPrompt;
use App\Models\LlmVisibilityAnswer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The visibility score and everything the panel says about it.
 *
 * One class rather than a query in the step and another in the controller,
 * because the number on the dashboard and the number on the analysis screen
 * disagreeing is the kind of bug nobody reports and everybody stops trusting
 * the product over.
 *
 * The denominator is answers that came back, not answers that were asked. An
 * assistant that declined is recorded with `mentioned = null` and left out of
 * both halves — counting a refusal as a miss makes an outage look like a
 * collapse, and this number's whole job is to be trusted when it moves.
 */
final class VisibilityReport
{
    /** How far behind the newest reading a platform falls before it is called out. */
    private const int STALE_AFTER_DAYS = 3;

    /** @param Collection<int, LlmVisibilityAnswer> $answers */
    private function __construct(
        private readonly Collection $answers,
        /** @var Collection<int, LlmPrompt> */
        private readonly Collection $prompts,
        public readonly ?Carbon $lastAskedOn,
    ) {}

    /**
     * The current standing: the newest answer for each prompt and assistant.
     *
     * Not "everything asked on the latest day", which is what this did first
     * and which the budget cap quietly breaks. `max_answers_per_run` stops a
     * sweep part-way through, so the rest is bought on the next run — often the
     * next day. Reading one day would then score the project on whichever
     * fraction happened to land last, and the headline would swing on the size
     * of the budget rather than on anything about the brand.
     *
     * Not an average over all history either: that moves more slowly the longer
     * a project runs, which is backwards for a number whose job is to notice
     * this week.
     *
     * So: the freshest reading per (prompt, assistant) inside the window.
     */
    public static function latest(int $withinDays = 30): self
    {
        $prompts = LlmPrompt::query()->where('is_active', true)->orderBy('locale')->orderBy('created_at')->get();

        if ($prompts->isEmpty()) {
            /** @var Collection<int, LlmVisibilityAnswer> $empty */
            $empty = new Collection;

            return new self($empty, $prompts, null);
        }

        $answers = LlmVisibilityAnswer::query()
            ->whereIn('llm_prompt_id', $prompts->modelKeys())
            ->where('asked_on', '>=', now()->subDays(max(1, $withinDays))->toDateString())
            // Newest first, so the keyBy below keeps the newest of each pair.
            ->orderByDesc('asked_on')
            ->orderByDesc('created_at')
            ->get();

        $current = $answers
            ->groupBy(static fn (LlmVisibilityAnswer $a): string => $a->llm_prompt_id.'|'.$a->platform)
            ->map(static fn (Collection $group): LlmVisibilityAnswer => $group->first())
            ->values();

        /** @var Collection<int, LlmVisibilityAnswer> $current */
        $lastAsked = $answers->first()?->asked_on;

        return new self($current, $prompts, $lastAsked === null ? null : Carbon::parse($lastAsked));
    }

    public function monitoredPrompts(): int
    {
        return $this->prompts->count();
    }

    /** Answers that actually came back. */
    public function answered(): int
    {
        return $this->answers->whereNotNull('mentioned')->count();
    }

    public function declined(): int
    {
        return $this->answers->whereNull('mentioned')->count();
    }

    public function mentions(): int
    {
        return $this->answers->where('mentioned', true)->count();
    }

    /**
     * Percent of answers that named the brand, or null when nothing was asked.
     *
     * Null rather than zero, and the screens must show the two differently. "We
     * asked and you were in none of them" and "nothing has been asked yet" look
     * identical as 0% and mean opposite things — the first is a problem to fix,
     * the second is a measurement that has not happened.
     */
    public function score(): ?float
    {
        $answered = $this->answered();

        return $answered === 0 ? null : round($this->mentions() / $answered * 100, 1);
    }

    public function moneySpent(): float
    {
        return round((float) $this->answers->sum(static fn (LlmVisibilityAnswer $a): float => (float) $a->money_spent), 4);
    }

    /**
     * The score broken out by language.
     *
     * The reason this class exists in the shape it does. A single percentage
     * over every locale hides the case that prompted the work: strong in one
     * language, absent in another, averaged into a number that describes
     * neither and hides the one that is sending customers.
     *
     * @return list<array{locale: string, score: float|null, answered: int, mentions: int, prompts: int}>
     */
    public function byLocale(): array
    {
        $localeOf = $this->prompts->pluck('locale', 'id');

        $rows = [];

        foreach ($this->prompts->groupBy('locale') as $locale => $group) {
            $answers = $this->answers->filter(
                static fn (LlmVisibilityAnswer $a): bool => ($localeOf[$a->llm_prompt_id] ?? null) === $locale,
            );

            $answered = $answers->whereNotNull('mentioned')->count();
            $mentions = $answers->where('mentioned', true)->count();

            $rows[] = [
                'locale' => (string) $locale,
                'score' => $answered === 0 ? null : round($mentions / $answered * 100, 1),
                'answered' => $answered,
                'mentions' => $mentions,
                'prompts' => $group->count(),
            ];
        }

        return $rows;
    }

    /**
     * The score broken out by assistant, each carrying the day it was measured.
     *
     * **Why the date is part of the row.** {@see latest()} keeps the freshest
     * answer per prompt *per platform*, which is right — a budget cap that stops
     * a sweep part-way must not throw away the half already paid for. What it
     * also means is that a platform which has stopped answering does not drop
     * out of the panel. It keeps contributing its last good reading, and a
     * reading from eleven days ago renders identically to one from this
     * morning.
     *
     * That is not hypothetical. On 19 August 2026 this returned four assistants
     * with nineteen answers each, and ChatGPT's nineteen were from the 8th: the
     * vendor had retired the model name in between, every sweep since had been
     * refused, and the headline was three assistants measured that morning
     * averaged with one measured eleven days earlier. Nothing on the screen
     * could have told anybody.
     *
     * So each row says when it was taken, and says so relative to the newest
     * reading in the report rather than to today — a panel where everything is
     * a fortnight old is a different fact, and {@see lastAskedOn} is where the
     * screen already says it.
     *
     * @return list<array{platform: string, score: float|null, answered: int, mentions: int, last_asked_on: string|null, stale: bool}>
     */
    public function byPlatform(): array
    {
        $rows = [];

        foreach ($this->answers->groupBy('platform') as $platform => $group) {
            $answered = $group->whereNotNull('mentioned')->count();

            /** @var Carbon|null $askedOn */
            $askedOn = $group->max('asked_on');

            $rows[] = [
                'platform' => (string) $platform,
                'score' => $answered === 0 ? null : round($group->where('mentioned', true)->count() / $answered * 100, 1),
                'answered' => $answered,
                'mentions' => $group->where('mentioned', true)->count(),
                'last_asked_on' => $askedOn?->toDateString(),
                'stale' => $this->isBehind($askedOn),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['answered'] <=> $a['answered']);

        return $rows;
    }

    /**
     * Which sites the assistants leaned on, most-cited first.
     *
     * @return list<array{host: string, citations: int, is_aggregator: bool}>
     */
    public function topSources(int $limit = 12): array
    {
        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($this->answers as $answer) {
            /** @var list<array{url: string, title: string}> $citations */
            $citations = $answer->citations;

            // Counted once per answer, not once per link. An assistant that
            // cites four pages of one site is one vote for that site.
            foreach (array_unique(array_map(self::hostOf(...), $citations)) as $host) {
                if ($host === '') {
                    continue;
                }

                $counts[$host] = ($counts[$host] ?? 0) + 1;
            }
        }

        arsort($counts);

        /** @var list<string> $aggregators */
        $aggregators = config('visibility.aggregator_hosts', []);

        $rows = [];

        foreach (array_slice($counts, 0, $limit, true) as $host => $citations) {
            $rows[] = [
                'host' => $host,
                'citations' => $citations,
                // Flagged rather than filtered. A directory dominating the
                // citations is a real finding — it means the assistants do not
                // know the businesses directly and getting listed there is the
                // cheaper move — but it is not a competitor.
                'is_aggregator' => self::isAggregator($host, $aggregators),
            ];
        }

        return $rows;
    }

    /**
     * The sites cited *instead of* this project's own, aggregators removed.
     *
     * Named for what it measures. This is not brand-entity extraction from the
     * prose — that would need another model call per answer and would be a
     * guess presented as a list. These are the sites the assistants actually
     * pointed at, which is a fact the response carries.
     *
     * @return list<array{host: string, citations: int}>
     */
    public function competitors(?string $ownSite, int $limit = 8): array
    {
        $own = self::hostOf(['url' => (string) $ownSite]);

        $rows = [];

        foreach ($this->topSources(64) as $source) {
            if ($source['is_aggregator']) {
                continue;
            }

            if ($own !== '' && ($source['host'] === $own || str_ends_with($source['host'], '.'.$own))) {
                continue;
            }

            $rows[] = ['host' => $source['host'], 'citations' => $source['citations']];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * One row per monitored prompt, with where the brand did and did not appear.
     *
     * @return list<array{
     *     id: string, text: string, locale: string, intent: string, intent_label: string,
     *     mentioned_in: list<string>, answered: int, declined: int
     * }>
     */
    public function promptRows(): array
    {
        $byPrompt = $this->answers->groupBy('llm_prompt_id');

        $rows = [];

        foreach ($this->prompts as $prompt) {
            /** @var Collection<int, LlmVisibilityAnswer> $answers */
            $answers = $byPrompt->get($prompt->id) ?? new Collection;

            $rows[] = [
                'id' => $prompt->id,
                'text' => $prompt->text,
                'locale' => $prompt->locale,
                'intent' => $prompt->intent->value,
                'intent_label' => $prompt->intent->label(),
                'mentioned_in' => array_values($answers->where('mentioned', true)->pluck('platform')->all()),
                'answered' => $answers->whereNotNull('mentioned')->count(),
                'declined' => $answers->whereNull('mentioned')->count(),
            ];
        }

        return $rows;
    }

    /**
     * Whether this platform's freshest reading trails the report's own.
     *
     * The threshold has to clear the ordinary spread inside one sweep without
     * reaching the gap between two. `max_answers_per_run` deliberately stops a
     * sweep short and the rest is bought on the next run — "often the next day",
     * as {@see latest()} puts it — so a lag of a day or two is the system
     * working. A weekly cadence puts a missed sweep at seven. Three sits in that
     * gap, near enough to the bottom of it that a platform quietly dropping out
     * is caught on the first sweep it misses rather than the second.
     */
    private function isBehind(?Carbon $askedOn): bool
    {
        if ($askedOn === null || $this->lastAskedOn === null) {
            return false;
        }

        return $askedOn->lt($this->lastAskedOn->copy()->subDays(self::STALE_AFTER_DAYS));
    }

    /** @param array{url?: string, title?: string} $citation */
    private static function hostOf(array $citation): string
    {
        $url = $citation['url'] ?? '';

        if ($url === '') {
            return '';
        }

        $host = parse_url(str_contains($url, '//') ? $url : 'https://'.$url, PHP_URL_HOST);

        if (! is_string($host)) {
            return '';
        }

        return (string) preg_replace('/^www\./', '', mb_strtolower($host));
    }

    /** @param list<string> $aggregators */
    private static function isAggregator(string $host, array $aggregators): bool
    {
        foreach ($aggregators as $aggregator) {
            $aggregator = mb_strtolower(trim($aggregator));

            if ($aggregator !== '' && ($host === $aggregator || str_ends_with($host, '.'.$aggregator))) {
                return true;
            }
        }

        return false;
    }
}
