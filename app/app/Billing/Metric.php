<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * The things a plan counts.
 *
 * An enum rather than loose strings because these names appear in three places
 * that must agree exactly — the config's limit keys, the counter rows' `metric`
 * column, and the props the paywall renders — and a typo in any one of them
 * reads as "unlimited" rather than as an error.
 *
 * Only counters live here. A plan's other limits (`locales`, `seats`,
 * `channels`, `weekly_target`, `cost_micros`) are *shape*: they bound a
 * standing configuration rather than accumulating over a period, and asking
 * "how many locales have you used this month" is not a question.
 */
enum Metric: string
{
    case AiAnswers = 'ai_answers';

    case PageImprovements = 'page_improvements';

    case Articles = 'articles';

    case SiteAudits = 'site_audits';

    case ContentPlans = 'content_plans';

    case AssistantTurns = 'assistant_turns';

    public function label(): string
    {
        return match ($this) {
            self::AiAnswers => 'AI answer checks',
            self::PageImprovements => 'reviewed page improvements',
            self::Articles => 'articles',
            self::SiteAudits => 'site audits',
            self::ContentPlans => 'content plans',
            self::AssistantTurns => 'assistant turns',
        };
    }

    /**
     * Is this something the engine does once a period on its own?
     *
     * Every plan allows exactly one content plan and one site audit a period,
     * and the engine makes both itself without anybody asking. So a used-up
     * counter here means the work got done, not that the manager ran short —
     * and reporting it as "you have used this period's content plans" beside
     * a warning triangle read, to a paying customer, as an error.
     *
     * The AI answers are deliberately not in here, although the scheduled
     * checks are sized to fill them: manual collections and rechecks spend the
     * same allowance, and when they do, the next scheduled check is refused.
     * That is a shortfall somebody has to hear about.
     *
     * The limit still applies; this only decides what is worth *saying*.
     */
    public function isRoutine(): bool
    {
        return match ($this) {
            self::ContentPlans, self::SiteAudits => true,
            default => false,
        };
    }
}
