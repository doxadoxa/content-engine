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
    | Medium, because that is the plan the engine's own default cadence fits: a
    | project written at seven a week does not fit Small, and starting somebody
    | on a plan their first month would overflow is a bad first invoice.
    |
    */

    'default_plan' => env('BILLING_DEFAULT_PLAN', 'medium'),

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

    'version' => 1,

    /*
    |--------------------------------------------------------------------------
    | The trial
    |--------------------------------------------------------------------------
    |
    | Three days, no card, capped three ways: time, units, and money.
    |
    | The money cap is the one that makes a card-free trial defensible. Every
    | signup spends real dollars at a provider — measured, a trial of this size
    | costs about $2.83 — so a hundred of them is a marketing budget with a
    | known worst case rather than an open tab.
    |
    | The clock starts when the engine does, not when the account is created.
    | Somebody who registers on Friday and finishes onboarding on Monday has not
    | had a trial over the weekend.
    |
    | Three articles in three days rather than two, deliberately: what Medium
    | sells is an article a day, and a trial delivering fewer than one a day
    | demonstrates something other than the thing being sold.
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
    | Measured cost of goods, from three weeks of real spend (see
    | `product/billing-and-admin-spec.md`): an article is about $0.16 all in and
    | a social post about $0.47, of which the picture is most of it. Pictures
    | cost three times what prose does, which is why no plan counts articles and
    | lets posts run free.
    |
    | `cost_micros` is the second layer of limit and is invisible to the
    | customer. The unit quotas are what they agreed to; this is what protects
    | the margin when a unit turns out to cost more than it was priced at — a
    | longer article, a redraw loop, a provider's price rising between the day a
    | plan was written and the day it is used. It sits at roughly three times
    | measured COGS.
    |
    | `weekly_target` is not a counter but a ceiling on the project's own dial,
    | the one `engine:tick` already reads. Clamping it makes the engine pace
    | itself so the month comes out even; the article counter behind it is the
    | backstop for the paths that bypass the tick.
    |
    */

    'plans' => [

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
