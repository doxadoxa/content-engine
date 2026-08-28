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
    case Articles = 'articles';

    case SocialPosts = 'social_posts';

    case SiteAudits = 'site_audits';

    case ContentPlans = 'content_plans';

    case AssistantTurns = 'assistant_turns';

    public function label(): string
    {
        return match ($this) {
            self::Articles => 'articles',
            self::SocialPosts => 'social posts',
            self::SiteAudits => 'site audits',
            self::ContentPlans => 'content plans',
            self::AssistantTurns => 'assistant turns',
        };
    }
}
