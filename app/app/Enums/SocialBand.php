<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The six полосы of §5 — what a social unit is for, and therefore what it costs.
 *
 * A band is not a topic and not a format. It is the answer to "which budget
 * does this come out of", which is the only question the governor asks. Two
 * posts about the same subject land in different bands when one is a reaction
 * to a news item and the other is a seasonal plan made six weeks ahead, and
 * they are counted separately because they carry different risk.
 *
 * The budget is a ceiling, never a plan. §5 is explicit: "недобор допустим,
 * перебор — нет". Nothing in the engine may read {@see weeklyBudget()} as a
 * quota to fill — an empty slot is a valid result (§4.3), and the instinct of a
 * content engine to fill a calendar is exactly what burns a Threads account.
 */
enum SocialBand: string
{
    /** Replies and joining somebody else's thread. Uncounted, never automatic. */
    case Conversation = 'conversation';

    /** A question, from the keyword pool or from PAA. The format that works best. */
    case Question = 'question';

    /** Prices, cases, what customers actually ask. The thing nobody else has. */
    case OwnData = 'own_data';

    /** Planned four to six weeks ahead of a demand peak. */
    case Season = 'season';

    /** A comment on something that just happened. Carries a TTL. */
    case Reaction = 'reaction';

    /** Something short left over from an article. One supplier of six, not the trunk. */
    case Derivative = 'derivative';

    public function label(): string
    {
        return match ($this) {
            self::Conversation => 'Conversation',
            self::Question => 'Question',
            self::OwnData => 'Own data',
            self::Season => 'Season',
            self::Reaction => 'Reaction',
            self::Derivative => 'Derivative',
        };
    }

    /**
     * Whether this band is counted against a weekly budget at all.
     *
     * The guard on {@see weeklyBudget()}, and the governor of §4.3 must read
     * the budget through it. Null there means "без счёта", and the obvious
     * spelling of the check — `$count >= $band->weeklyBudget()` — evaluates to
     * `$count >= 0` for `Conversation`, so PHP turns "uncounted" into "never"
     * silently and in the direction that shuts off the half of the channel §1
     * says is worth the most. Ask this first; only then read the number.
     */
    public function isCounted(): bool
    {
        return $this->weeklyBudget() !== null;
    }

    /**
     * How many published units a week this band may account for at most.
     *
     * Null for `Conversation`, which is not counted at all: §1's second fact is
     * that replies are worth about as much as posts and that the accounts which
     * grow are the ones replying more than they write. A weekly cap on talking
     * to people would be a cap on the half of the channel that works.
     *
     * Never compare against this without {@see isCounted()} — a null in a
     * numeric comparison is a zero, and zero is the one answer that is wrong.
     */
    public function weeklyBudget(): ?int
    {
        return match ($this) {
            self::Conversation => null,
            self::Question => 2,
            self::OwnData => 1,
            self::Season => 1,
            self::Reaction => 1,
            self::Derivative => 1,
        };
    }

    /**
     * Whether autopublish is reachable for this band at all.
     *
     * False here means "никогда", not "not yet". The other four bands merely
     * *earn* autopublish — they still have to pass the governor and whatever
     * the project's own tumbler says — but these two have no path to it in code:
     *
     *   Conversation — a reply to a living person, sent as the brand, with no
     *   human in the loop. §4.2 refuses this permanently, and the phase note is
     *   equally blunt: the band has no route to automatic sending at all.
     *
     *   Reaction — a comment on news, which is the one place where being wrong
     *   is both fast and public. §5 marks it never for the same reason its
     *   drafts are killed rather than published late.
     */
    public function canEverAutopublish(): bool
    {
        return match ($this) {
            self::Conversation, self::Reaction => false,
            self::Question, self::OwnData, self::Season, self::Derivative => true,
        };
    }
}
