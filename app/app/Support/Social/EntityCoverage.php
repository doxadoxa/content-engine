<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\Project;
use App\Models\Signal;
use App\Models\SitePage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What the project talks about, against what the site has a page for (§6).
 *
 * §6's fifth signal and its first consequence in one pass: "о чём говорим, чего
 * нет на сайте", and then "Тема, по которой в Threads есть разговор и отклик, а
 * на сайте нет страницы, уходит в планировщик статей с приоритетом." So the
 * output is not a percentage. A coverage score of 62% is a number nobody can
 * act on; a list saying "hard water came up four times in conversation and
 * three times in replies, and the site has no page about it" is a task.
 *
 * **Both halves are already in the engine and neither is recomputed here.** The
 * listening contour of §4.1 resolves every signal into the project's vocabulary
 * as it arrives and stores the result on the row, and {@see ProjectVocabulary}
 * is the same resolver the reply gate uses — a second definition of "this text
 * is about hard water" would put two different answers on one screen. The site
 * side is {@see SitePage}, which the onboarding crawl fills, plus the units this
 * engine itself published.
 *
 * Nothing here calls a model or the network, which is what makes it affordable
 * to run once a day per project forever, and reproducible in a test.
 */
final class EntityCoverage
{
    /**
     * How many gaps one snapshot names.
     *
     * A cap rather than everything, because this is a jsonb column written
     * daily and the tail of the list is a subject somebody mentioned once. The
     * planner can only act on a handful a week anyway.
     */
    private const int MAX_GAPS = 20;

    /** The same cap on the other side, and for the same reason. */
    private const int MAX_COVERED = 20;

    /** A crawl is not free to hold in memory; a small business site fits. */
    private const int MAX_PAGES = 500;

    public function __construct(private readonly ProjectVocabulary $vocabulary) {}

    /**
     * The day's conversation, matched against the site.
     *
     * The window is a day rather than a trend because the row is a day; the
     * trend is what stacking the rows gives you, which is the argument §6 makes
     * for storing snapshots at all.
     *
     * @return array{pages: int, covered: list<string>, gaps: list<array{entity: string, signals: int, interactions: int}>}
     */
    public function for(Project $project, CarbonInterface $from, CarbonInterface $to): array
    {
        $discussed = $this->discussed($project, $from, $to);

        // Longest first, which is what {@see ProjectVocabulary::resolve()}
        // expects: a page about "hard water" must not be read as covering
        // "water" as a separate subject.
        $entities = array_keys($discussed);

        usort($entities, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $pages = $this->pages();
        $covered = [];

        foreach ($pages as $text) {
            foreach (ProjectVocabulary::resolve($text, $entities) as $entity) {
                $covered[$entity] = true;
            }
        }

        $gaps = [];

        foreach ($discussed as $entity => $counts) {
            if (isset($covered[$entity])) {
                continue;
            }

            $gaps[] = [
                'entity' => $entity,
                'signals' => $counts['signals'],
                'interactions' => $counts['interactions'],
            ];
        }

        // Loudest first: §6 sends the gap to the article planner "с
        // приоритетом", and the priority is how much conversation there was.
        usort($gaps, static fn (array $a, array $b): int => [
            $b['signals'] + $b['interactions'], $a['entity'],
        ] <=> [
            $a['signals'] + $a['interactions'], $b['entity'],
        ]);

        return [
            // Carried so that "no gaps" can be told apart from "no site read
            // yet". A project whose crawl never ran covers nothing, and
            // reporting that as full coverage would be the worst answer here.
            'pages' => count($pages),
            'covered' => array_slice(array_map(strval(...), array_keys($covered)), 0, self::MAX_COVERED),
            'gaps' => array_slice($gaps, 0, self::MAX_GAPS),
        ];
    }

    /**
     * Every subject that came up in the window, with which half it came from.
     *
     * Two halves because §6's consequence needs both: "разговор и отклик" — a
     * subject somebody posted about, and a subject somebody answered us about.
     * A keyword that shows up in a hundred strangers' posts and never in a
     * reply is a different thing from one three people asked us directly.
     *
     * @return array<string, array{signals: int, interactions: int}>
     */
    private function discussed(Project $project, CarbonInterface $from, CarbonInterface $to): array
    {
        /** @var array<string, int> $fromSignals */
        $fromSignals = [];
        /** @var array<string, int> $fromInteractions */
        $fromInteractions = [];

        // Bound in UTC. Laravel formats a bound date with `format('Y-m-d
        // H:i:s')` and drops the offset, so a window expressed in the
        // project's local time is read back in the session timezone and the
        // day silently moves — the same trap {@see \App\Social\Governor} notes
        // about its week.
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();

        // Signals arrive with their entities already resolved by §4.1's intake,
        // so this side costs one query and no matching.
        $signals = Signal::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['entities']);

        foreach ($signals as $signal) {
            foreach ($signal->entities as $entity) {
                $entity = trim((string) $entity);

                if ($entity !== '') {
                    $fromSignals[$entity] = ($fromSignals[$entity] ?? 0) + 1;
                }
            }
        }

        $interactions = Interaction::query()
            ->whereBetween('received_at', [$from, $to])
            ->get(['text']);

        if ($interactions->isNotEmpty()) {
            // Resolved rather than stored, because an interaction is a reply to
            // us and nothing upstream had a reason to tag it with subjects.
            $vocabulary = $this->vocabulary->entities($project);

            foreach ($interactions as $interaction) {
                foreach (ProjectVocabulary::resolve($interaction->text, $vocabulary) as $entity) {
                    $fromInteractions[$entity] = ($fromInteractions[$entity] ?? 0) + 1;
                }
            }
        }

        $counted = [];

        foreach ([...array_keys($fromSignals), ...array_keys($fromInteractions)] as $entity) {
            $entity = (string) $entity;

            $counted[$entity] = [
                'signals' => $fromSignals[$entity] ?? 0,
                'interactions' => $fromInteractions[$entity] ?? 0,
            ];
        }

        return $counted;
    }

    /**
     * The text of everything the site already has a page for.
     *
     * Both sources, because they answer the question at different times in a
     * project's life. {@see SitePage} is the site as it was before this engine
     * touched it — the onboarding crawl — and is the only record of the pages
     * somebody else wrote. Published units are what the engine has added since,
     * and a subject we published about last week is covered whether or not the
     * crawl has run again.
     *
     * @return list<string>
     */
    private function pages(): array
    {
        $texts = SitePage::query()
            ->limit(self::MAX_PAGES)
            ->get(['title', 'description'])
            ->map(static fn (SitePage $page): string => trim($page->title.' '.($page->description ?? '')))
            ->all();

        $units = ContentItem::query()
            ->whereIn('state', [ContentItemState::Published->value, ContentItemState::Refreshing->value])
            ->whereNotNull('public_url')
            ->limit(self::MAX_PAGES)
            ->get(['title', 'entities'])
            ->map(static fn (ContentItem $unit): string => trim(
                $unit->title.' '.implode(' ', $unit->entities)
            ))
            ->all();

        return array_values(array_filter(
            [...$texts, ...$units],
            static fn (string $text): bool => $text !== '',
        ));
    }
}
