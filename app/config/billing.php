<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | What a project may do, and what it costs
    |--------------------------------------------------------------------------
    |
    | A subscription belongs to a project, because a project is the tenant: the
    | scope every read is filtered by, the thing spend accrues against, and the
    | unit the customer recognises as "my site". One payer can hold several.
    |
    | Plans live here rather than in the database for the same reason model
    | prices do (config/models.php): they are a *decision*, they belong in
    | review, and a row somebody edited in production is not either of those.
    |
    | One list, and it is the one being sold. Nobody has paid for anything yet,
    | so there is no older price list to keep honouring; re-pricing once people
    | do will need a way to keep them on what they bought.
    |
    | Every limit is per project per billing period. `null` means unlimited and
    | is never a synonym for zero — a plan that forgot to name a limit must not
    | silently forbid the thing.
    |
    */

    'currency' => env('BILLING_CURRENCY', 'eur'),

    /*
    |--------------------------------------------------------------------------
    | What a new project starts on
    |--------------------------------------------------------------------------
    |
    | The wizard's last step sends somebody to a checkout, and a checkout needs
    | a price. This is the plan it names — the free days are the same whichever
    | one it is, so this only decides what happens on day three.
    |
    */

    'default_plan' => env('BILLING_DEFAULT_PLAN', 'starter'),

    /*
    |--------------------------------------------------------------------------
    | The trial
    |--------------------------------------------------------------------------
    |
    | Three days with a payment method at checkout, capped by time, units and cost.
    |
    | It includes one first-accepted page improvement and a bounded provider
    | budget. The clock starts when launch begins, not registration.
    |
    */

    'trial' => [
        'days' => (int) env('BILLING_TRIAL_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dunning
    |--------------------------------------------------------------------------
    |
    | How long a project keeps working after a payment fails. Generation stops
    | at once; reading and publishing continue to the end of the grace, because
    | holding approved work hostage to a declined card turns a billing problem
    | into a support incident and a refund.
    |
    */

    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | The plans
    |--------------------------------------------------------------------------
    |
    | Cost observations from three weeks of spend (see
    | `product/billing-and-admin-spec.md`): an article is about $0.16 all in.
    |
    | `cost_micros` is the second layer of limit and is invisible to the
    | customer. The unit quotas are what they agreed to; this is what protects
    | the delivery budget when a unit turns out to cost more than it was priced at — a
    | longer article, a redraw loop, a provider's price rising between the day a
    | plan was written and the day it is used. The caps are provisional;
    | sustainable delivery economics require actual operator-time records.
    |
    | `weekly_target` is not a counter but a ceiling on the project's own dial,
    | the one `engine:tick` already reads. Clamping it makes the engine pace
    | itself so the month comes out even; the article counter behind it is the
    | backstop for the paths that bypass the tick.
    |
    | The Stripe price variables keep the names the deployments already set:
    | Starter reads `STRIPE_PRICE_SMALL` and Growth `STRIPE_PRICE_MEDIUM`. The
    | checkout verifies each price against the amount and currency here before
    | anybody is sent to pay it.
    |
    */

    'plans' => [
        'preview' => [
            'name' => 'Preview', 'price_cents' => 0, 'currency' => 'usd',
            'self_serve' => false, 'stripe_price' => null,
            'limits' => [
                // One article, and the month's plan around it. The plan is
                // most of what this is for: a calendar of topics chosen
                // from real search evidence says more about whether this
                // is worth paying for than a single article does, and it
                // costs one research run to make. `articles` is overridden
                // per subscription to the count of whatever plan was
                // selected, so the calendar shown is the calendar bought.
                'articles' => 12, 'content_plans' => 1, 'social_posts' => 0,
                'page_improvements' => 0, 'site_audits' => 1, 'assistant_turns' => 0,
                // The AI visibility check is a paid thing and stays one.
                'ai_answers' => 0, 'ai_questions' => 0, 'ai_frequency_days' => 30,
                'locales' => 1, 'seats' => 2, 'channels' => 1, 'tracked_pages' => 20,
                'weekly_target' => 7, 'audit_refresh_days' => 7,
                // $2.50. Measured, a plan plus an audit plus one article is
                // about $1.70, so this is headroom rather than a limit
                // anybody reaches — and it is the hard floor under the cost
                // of a signup that never adds a card.
                'cost_micros' => 2_500_000,
            ],
        ],
        'trial' => [
            'name' => 'Website growth trial', 'price_cents' => 0, 'currency' => 'usd',
            'self_serve' => false, 'stripe_price' => null,
            'limits' => [
                'articles' => 3, 'content_plans' => 1, 'social_posts' => 0,
                'page_improvements' => 1, 'site_audits' => 1, 'assistant_turns' => 20,
                'ai_answers' => 12, 'ai_questions' => 3, 'ai_frequency_days' => 30,
                'locales' => 1, 'seats' => 2, 'channels' => 1, 'tracked_pages' => 20,
                'weekly_target' => 7, 'audit_refresh_days' => 7, 'cost_micros' => 5_000_000,
            ],
        ],
        'starter' => [
            'name' => 'Starter', 'price_cents' => 2_900, 'currency' => 'usd',
            'self_serve' => true, 'stripe_price' => env('STRIPE_PRICE_SMALL'),
            'limits' => [
                'articles' => 12, 'content_plans' => 1, 'social_posts' => 0,
                'page_improvements' => 0, 'site_audits' => 1, 'assistant_turns' => 100,
                'ai_answers' => 12, 'ai_questions' => 3, 'ai_frequency_days' => 30,
                'locales' => 1, 'seats' => 2, 'channels' => 1, 'tracked_pages' => 20,
                'weekly_target' => 3, 'audit_refresh_days' => 30, 'cost_micros' => 25_000_000,
            ],
        ],
        'growth' => [
            'name' => 'Growth', 'price_cents' => 8_900, 'currency' => 'usd',
            'self_serve' => true, 'stripe_price' => env('STRIPE_PRICE_MEDIUM'),
            'limits' => [
                'articles' => 30, 'content_plans' => 1, 'social_posts' => 0,
                'page_improvements' => 4, 'site_audits' => 1, 'assistant_turns' => 100,
                'ai_answers' => 200, 'ai_questions' => 10, 'ai_frequency_days' => 7,
                'locales' => 1, 'seats' => 2, 'channels' => 1, 'tracked_pages' => 20,
                'weekly_target' => 7, 'audit_refresh_days' => 30, 'cost_micros' => 75_000_000,
            ],
        ],
    ],

];
