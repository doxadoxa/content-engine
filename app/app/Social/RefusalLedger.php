<?php

declare(strict_types=1);

namespace App\Social;

use App\Enums\ChannelType;
use App\Enums\InteractionSkipReason;
use App\Enums\InteractionState;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\Project;
use App\Models\SocialPlan;
use Carbon\CarbonImmutable;

/**
 * What the engine did not do this week, and why.
 *
 * §7's mandatory line:
 *
 * > Молчащий автомат неотличим от сломанного, и единственная вещь, которая
 * > делает «сегодня не публикуем» приемлемой, — объяснение рядом.
 *
 * **A class rather than a controller method, because the screen it belongs to
 * moved.** This was `DailySummaryController::notDone()`, on a `Today` page that
 * was one of three landing screens all reading as "dashboard" to the person
 * looking at them. The paragraph above asks for the explanation to sit next to
 * the silence — it never asked for a URL of its own, and a tab nobody opens is
 * a worse home for it than a band on the screen everybody lands on.
 *
 * The three things §7 makes load-bearing all survive the move and are the
 * reason this is tested directly:
 *
 * - It **always** returns a sentence. A week that refused nothing gets a line
 *   saying so; it never gets an empty list, because an empty list is the
 *   silence the paragraph forbids.
 * - A missing plan is itself an entry. "Nobody decided anything" and "we
 *   decided to post nothing" are different facts and must not render alike.
 * - {@see self::severity()} says how loudly to say it, which is the one thing
 *   the old screen got wrong: it drew "The week was never planned" and "Nothing
 *   was refused this week" in the same rose-coloured card, so an operator had
 *   to read prose to tell a catastrophe from an all-clear.
 */
final class RefusalLedger
{
    /** How many skipped conversations are worth listing before it is a queue. */
    private const int PREVIEW = 5;

    /**
     * Codes that mean the engine stopped rather than chose.
     *
     * `not_planned` is here because nothing ran at all, and `throttled` because
     * §4.3 cut the week's ceiling on the back of a weak one — both are the
     * operator's problem this week. An empty slot or a skipped conversation is
     * the engine working as designed and stays quiet.
     */
    private const array LOUD = ['not_planned', 'throttled', 'floor'];

    /**
     * @return array{
     *     week_start: string,
     *     entries: list<array{code: string, label: string, detail: string, at: string|null}>,
     *     throttle: array{reply_rate: float|null, ceiling: int, selection_floor: int, detail: string}|null,
     *     alert: string|null,
     *     planned: int|null,
     *     ceiling: int|null,
     *     floor: int|null,
     *     nothing_to_report: bool,
     *     severity: string,
     *     summary: string,
     * }
     */
    public function for(Project $project, CarbonImmutable $now): array
    {
        $weekStart = SocialPlan::weekStart($project, $now);
        $plan = SocialPlan::forWeek($project, $now);

        /** @var list<array{code: string, label: string, detail: string, at: string|null}> $entries */
        $entries = [];

        foreach ($plan === null ? [] : $plan->reasons as $reason) {
            $entries[] = [
                'code' => $reason['code'],
                'label' => self::label($reason['code']),
                'detail' => $reason['detail'],
                'at' => $reason['at'],
            ];
        }

        $throttle = null;

        if ($plan !== null && $plan->throttled) {
            $rate = $plan->reply_rate;

            $throttle = [
                'reply_rate' => $rate,
                'ceiling' => $plan->ceiling,
                'selection_floor' => $plan->selection_floor,
                'detail' => 'The trailing reply rate '
                    .($rate === null ? 'fell below the threshold' : 'is '.number_format($rate * 100, 2).'%')
                    .", so §4.3 cut this week's ceiling to {$plan->ceiling} posts and raised the selection bar "
                    ."to {$plan->selection_floor}. Fewer posts and a pickier engine, both on purpose: a weak "
                    .'week costs the next one its reach.',
            ];

            $entries[] = [
                'code' => 'throttled',
                'label' => self::label('throttled'),
                'detail' => $throttle['detail'],
                'at' => null,
            ];
        }

        if ($plan !== null && $plan->alert !== null) {
            $entries[] = [
                'code' => 'floor',
                'label' => self::label('floor'),
                'detail' => $plan->alert,
                'at' => null,
            ];
        }

        foreach ($this->skipped($weekStart) as $entry) {
            $entries[] = $entry;
        }

        // A project that posts and has no record of a decision for this week is
        // the silence §7 is actually about: the planner did not run, and
        // without this line the screen would look like a week in which nothing
        // needed doing. Gated on the project having a presence at all, because
        // on a blog-only project the absence of a social plan is not news.
        if ($plan === null && $this->hasPresence()) {
            $entries[] = [
                'code' => 'not_planned',
                'label' => self::label('not_planned'),
                'detail' => 'No planning run has recorded a decision for the week of '
                    .$weekStart->toDateString().'. That is not the same as deciding to post nothing — '
                    .'nobody decided anything, which usually means the weekly planner did not run.',
                'at' => null,
            ];
        }

        $nothing = $entries === [];

        return [
            'week_start' => $weekStart->toDateString(),
            'entries' => $entries,
            'throttle' => $throttle,
            'alert' => $plan?->alert,
            'planned' => $plan?->planned,
            'ceiling' => $plan?->ceiling,
            'floor' => $plan?->floor,
            'nothing_to_report' => $nothing,
            'severity' => self::severity($entries),
            // Always a sentence, never an empty panel. §7's last line is
            // mandatory, and "the engine refused nothing" is itself the
            // explanation that makes today's silence readable.
            'summary' => $nothing
                ? 'Nothing was refused this week. No slot was left empty, no draft was killed, the governor '
                    .'did not throttle, and no conversation was skipped — the engine has nothing it declined '
                    .'to do.'
                : count($entries).' '.(count($entries) === 1 ? 'thing' : 'things')
                    .' the engine did not do this week, each with the reason it did not.',
        ];
    }

    /**
     * Whether the band should raise its voice.
     *
     * The band is collapsed by default on a screen that leads with a composer,
     * so "there is something in here" has to be legible without opening it —
     * otherwise moving §7's line off its own page really would bury it, which
     * is the strongest argument against having moved it.
     *
     * @param  list<array{code: string, label: string, detail: string, at: string|null}>  $entries
     */
    private static function severity(array $entries): string
    {
        if ($entries === []) {
            return 'clear';
        }

        foreach ($entries as $entry) {
            if (in_array($entry['code'], self::LOUD, true)) {
                return 'alarm';
            }
        }

        return 'noted';
    }

    /**
     * Conversations a person decided to leave alone, this week.
     *
     * On the same list as the engine's refusals because they are the same kind
     * of fact — a thread that got no answer — and §7's line is one line. The
     * reason is a code from a closed set, so the label is the operator's own
     * words rather than a sentence reconstructed here.
     *
     * @return list<array{code: string, label: string, detail: string, at: string|null}>
     */
    private function skipped(CarbonImmutable $weekStart): array
    {
        /** @var list<array{code: string, label: string, detail: string, at: string|null}> $rows */
        $rows = Interaction::query()
            ->where('state', InteractionState::Ignored)
            ->whereNotNull('ignored_reason')
            ->where('updated_at', '>=', $weekStart->utc())
            ->latest('updated_at')
            ->limit(self::PREVIEW)
            ->get()
            ->map(static function (Interaction $conversation): array {
                $reason = InteractionSkipReason::tryFrom((string) $conversation->ignored_reason);
                $who = $conversation->author_handle ?? $conversation->author;

                return [
                    'code' => 'skipped',
                    'label' => self::label('skipped'),
                    'detail' => "{$who} was left unanswered: "
                        .($reason?->label() ?? (string) $conversation->ignored_reason).'.',
                    'at' => $conversation->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return $rows;
    }

    /**
     * Whether this project has a social presence to be silent about.
     *
     * Cheap on purpose — two `exists()` against indexed columns — because it
     * only ever decides whether one extra line appears.
     */
    private function hasPresence(): bool
    {
        return Channel::query()->where('type', ChannelType::Threads)->exists()
            || ContentItem::query()->social()->exists();
    }

    /** The heading a refusal appears under. One per code the engine writes. */
    private static function label(string $code): string
    {
        return match ($code) {
            'empty_slot' => 'Slot left empty',
            'killed' => 'Draft killed rather than published late',
            'throttled' => 'The week was throttled',
            'floor' => 'Below the floor',
            'skipped' => 'Conversation skipped',
            'not_planned' => 'The week was never planned',
            default => 'Not done',
        };
    }
}
