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
    | Version 2 focuses on reviewed existing-page improvements. Historical
    | article packages remain pinned to version 1 for their subscribers.
    |
    */

    'default_plan' => env('BILLING_DEFAULT_PLAN', 'starter'),

    /*
    |--------------------------------------------------------------------------
    | The price list version
    |--------------------------------------------------------------------------
    |
    | Versioned exactly the way `config/models.php` versions what a token costs,
    | and for the sharper reason: this is what a customer was *sold*. A project
    | keeps the entitlements of the version its subscription was opened under,
    | so re-pricing publishes a new version and never edits a published one.
    | Editing version 1 in place silently changes what people who are already
    | paying are allowed to do.
    |
    */

    'version' => 4,

    /*
    |--------------------------------------------------------------------------
    | The trial
    |--------------------------------------------------------------------------
    |
    | Three days with a payment method at checkout, capped by time, units and cost.
    |
    | Version 2 includes one first-accepted page improvement and a bounded
    | provider budget. The clock starts when launch begins, not registration.
    | Legacy version 1 article trial allowances remain unchanged below.
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
    | Historical version 1 cost observations, from three weeks of spend (see
    | `product/billing-and-admin-spec.md`): an article is about $0.16 all in and
    | a social post about $0.47, of which the picture is most of it. Pictures
    | cost three times what prose does, which is why no plan counts articles and
    | lets posts run free.
    |
    | `cost_micros` is the second layer of limit and is invisible to the
    | customer. The unit quotas are what they agreed to; this is what protects
    | the delivery budget when a unit turns out to cost more than it was priced at — a
    | longer article, a redraw loop, a provider's price rising between the day a
    | plan was written and the day it is used. Version 2 caps are provisional;
    | sustainable delivery economics require actual operator-time records.
    |
    | `weekly_target` is not a counter but a ceiling on the project's own dial,
    | the one `engine:tick` already reads. Clamping it makes the engine pace
    | itself so the month comes out even; the article counter behind it is the
    | backstop for the paths that bypass the tick.
    |
    */

    'plans' => [

        4 => [
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
                'self_serve' => true, 'stripe_price' => env('STRIPE_PRICE_STARTER_V4'),
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
                'self_serve' => true, 'stripe_price' => env('STRIPE_PRICE_GROWTH_V4'),
                'limits' => [
                    'articles' => 30, 'content_plans' => 1, 'social_posts' => 0,
                    'page_improvements' => 4, 'site_audits' => 1, 'assistant_turns' => 100,
                    'ai_answers' => 200, 'ai_questions' => 10, 'ai_frequency_days' => 7,
                    'locales' => 1, 'seats' => 2, 'channels' => 1, 'tracked_pages' => 20,
                    'weekly_target' => 7, 'audit_refresh_days' => 30, 'cost_micros' => 75_000_000,
                ],
            ],
        ],

        3 => [
            'trial' => [
                'name' => 'Content growth trial', 'price_cents' => 0, 'currency' => 'usd',
                'self_serve' => false, 'stripe_price' => null,
                'limits' => [
                    'articles' => 3, 'content_plans' => 1, 'social_posts' => 0,
                    'page_improvements' => 1, 'site_audits' => 1, 'assistant_turns' => 20,
                    'locales' => 1, 'seats' => 2, 'channels' => 1, 'tracked_pages' => 20,
                    'weekly_target' => 7, 'audit_refresh_days' => 7, 'cost_micros' => 5_000_000,
                ],
            ],
            'local-search' => [
                'name' => 'Content Growth', 'price_cents' => 8_900, 'currency' => 'usd',
                'self_serve' => true, 'stripe_price' => env('STRIPE_PRICE_CONTENT_GROWTH'),
                'limits' => [
                    'articles' => 30, 'content_plans' => 1, 'social_posts' => 0,
                    'page_improvements' => 4, 'site_audits' => 1, 'assistant_turns' => 100,
                    'locales' => 1, 'seats' => 2, 'channels' => 1, 'tracked_pages' => 20,
                    'weekly_target' => 7, 'audit_refresh_days' => 30, 'cost_micros' => 25_000_000,
                ],
            ],
        ],

        2 => [
            'trial' => [
                'name' => 'Website improvement trial', 'price_cents' => 0, 'currency' => 'usd',
                'self_serve' => false, 'stripe_price' => null,
                'limits' => [
                    'page_improvements' => 1, 'articles' => 0, 'social_posts' => 0,
                    'site_audits' => 1, 'content_plans' => 0, 'assistant_turns' => 20,
                    'locales' => 1, 'seats' => 2, 'channels' => 1, 'tracked_pages' => 20,
                    'weekly_target' => 1, 'audit_refresh_days' => 7, 'cost_micros' => 5_000_000,
                ],
            ],
            'local-search' => [
                'name' => 'Local Search', 'price_cents' => 8_900, 'currency' => 'usd',
                'self_serve' => true, 'stripe_price' => env('STRIPE_PRICE_LOCAL_SEARCH'),
                // Initial package to validate with measured support time. No
                // existing subscription is migrated to this version implicitly.
                'limits' => [
                    'page_improvements' => 4, 'articles' => 0, 'social_posts' => 0,
                    'site_audits' => 1, 'content_plans' => 0, 'assistant_turns' => 100,
                    'locales' => 1, 'seats' => 2, 'channels' => 1, 'tracked_pages' => 20,
                    'weekly_target' => 1, 'audit_refresh_days' => 30, 'cost_micros' => 25_000_000,
                ],
            ],
        ],

        1 => [

            /*
             * The free window is a plan like any other, and it lives in the
             * versioned list for the same reason the paid ones do: a project
             * keeps what it was opened under. Held outside this list it was the
             * one entitlement re-pricing could still change under somebody
             * mid-trial, which is the exact thing the versioning exists to
             * prevent.
             *
             * Every consumer — the tick, the middleware, the banner — asks a
             * trial the same questions it asks Medium, so it arrives in the
             * same shape.
             */
            'trial' => [
                'name' => 'Trial',
                'price_cents' => 0,
                'self_serve' => false,
                'stripe_price' => null,
                'limits' => [
                    'articles' => 3,
                    'social_posts' => 5,
                    'site_audits' => 1,
                    'content_plans' => 1,
                    'assistant_turns' => 20,
                    'locales' => 1,
                    'seats' => 2,
                    'channels' => 1,
                    // Seven, so three days really do deliver one a day — what
                    // Medium sells is a daily article, and a trial that cannot
                    // demonstrate the cadence demonstrates something else.
                    'weekly_target' => 7,
                    'audit_refresh_days' => 7,
                    // $5. Measured, the caps above cost about $2.83, so this is
                    // headroom rather than a limit anybody reaches — it is here
                    // for the retry storm and the creative visitor, not for the
                    // customer.
                    'cost_micros' => 5_000_000,
                ],
            ],

            'small' => [
                'name' => 'Small',
                'price_cents' => 2_900,
                'self_serve' => true,
                'stripe_price' => env('STRIPE_PRICE_SMALL'),
                'limits' => [
                    'articles' => 10,
                    'social_posts' => 10,
                    'site_audits' => 1,
                    'content_plans' => 1,
                    'assistant_turns' => 100,
                    'locales' => 1,
                    'seats' => 2,
                    'channels' => 1,
                    'weekly_target' => 2,
                    'audit_refresh_days' => 30,
                    // Measured COGS ~$6.30.
                    'cost_micros' => 20_000_000,
                ],
            ],

            'medium' => [
                'name' => 'Medium',
                'price_cents' => 9_900,
                'self_serve' => true,
                'stripe_price' => env('STRIPE_PRICE_MEDIUM'),
                'limits' => [
                    // One a day. The market has already priced a daily article
                    // at this money, and the engine's own default cadence
                    // (`weekly_target` 7) was already here before anybody
                    // billed for it — a smaller number would have been a limit
                    // below the product's own behaviour.
                    'articles' => 30,
                    'social_posts' => 30,
                    'site_audits' => 4,
                    'content_plans' => 2,
                    'assistant_turns' => 500,
                    'locales' => 3,
                    'seats' => 5,
                    'channels' => null,
                    'weekly_target' => 7,
                    'audit_refresh_days' => 7,
                    // Measured COGS ~$19.
                    'cost_micros' => 60_000_000,
                ],
            ],

            'enterprise' => [
                'name' => 'Enterprise',
                'price_cents' => 39_900,
                // Not self-serve: an administrator provisions it against a
                // custom Stripe price, and the limits that differ from these
                // are stored on the subscription row rather than here.
                'self_serve' => false,
                'stripe_price' => env('STRIPE_PRICE_ENTERPRISE'),
                'limits' => [
                    'articles' => null,
                    'social_posts' => null,
                    'site_audits' => null,
                    'content_plans' => null,
                    'assistant_turns' => null,
                    'locales' => null,
                    'seats' => null,
                    'channels' => null,
                    'weekly_target' => 14,
                    'audit_refresh_days' => 7,
                    // Unlimited units, and still a fuse. "Custom" is a pricing
                    // conversation, not a promise that one tenant may spend
                    // without bound before anybody notices.
                    'cost_micros' => 500_000_000,
                ],
            ],

        ],

    ],

];
