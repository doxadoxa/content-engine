<?php

declare(strict_types=1);

namespace App\Publishing\Articles;

/**
 * Why a schedule is blocked, as `article_schedules.blocked_code`.
 *
 * `blocked_reason` stays the sentence; this is what code reads. Stable
 * strings: they are stored, and screens map them to their own copy.
 */
final class BlockedCode
{
    /** No usable website at all. */
    public const string NO_WEBSITE = 'no_website';

    /** More than one usable website, and none chosen. */
    public const string CHOOSE_WEBSITE = 'choose_website';

    /** The date passed without the article going out; it needs a new one. */
    public const string MISSED_DATE = 'missed_date';

    /** The plan does not allow publishing. */
    public const string PLAN = 'plan';

    /** No articles left in this period. */
    public const string ALLOWANCE_USED = 'allowance_used';

    /** Content work for the project is paused. */
    public const string PROJECT_PAUSED = 'project_paused';

    /** Waiting for a person to approve it. */
    public const string NEEDS_APPROVAL = 'needs_approval';

    /** The fact check has not passed, so Avyo will not approve it itself. */
    public const string FACT_CHECK = 'fact_check';

    /** The quality score says the draft is not ready. */
    public const string SCORE = 'score';

    /** Business facts the article relies on are missing or out of date. */
    public const string BUSINESS_FACTS = 'business_facts';

    /** The owner switched the chosen website off. */
    public const string WEBSITE_PAUSED = 'website_paused';

    /** The chosen website is on but not working: never tested, failed its last test, or missing its secret or address. */
    public const string WEBSITE_NOT_WORKING = 'website_not_working';

    /** An earlier delivery of this article needs attention first. */
    public const string PREVIOUS_DELIVERY = 'previous_delivery';

    public const string OTHER = 'other';
}
