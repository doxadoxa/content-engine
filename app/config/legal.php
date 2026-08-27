<?php

declare(strict_types=1);

/*
 * Who Avyo is, legally, and what it does with people's data.
 *
 * Three public pages — the terms, the privacy policy and the cookie policy —
 * repeat the same handful of facts, and a company that moves its registered
 * office should not have to be found by grep. So the facts live here once and
 * the pages read them.
 *
 * The two inventories at the bottom are the part that rots. A cookie policy
 * listing a cookie we no longer set, or a privacy policy silent about a
 * provider we started sending drafts to last month, is worse than no policy at
 * all: it is a statement about our processing that is false. Both lists are
 * asserted against the code in tests/Feature/Legal, so adding a cookie or a
 * provider without saying so here fails the suite rather than quietly making
 * the published policy a lie.
 */
return [

    /*
     * The controller. Every value is overridable by env because the entity is
     * shared with other products and may be restated without a deploy — but
     * the defaults are the real registered facts, not placeholders.
     */
    'entity' => env('LEGAL_ENTITY', 'Courtly Ltd'),
    'company_number' => env('LEGAL_COMPANY_NUMBER', '17009343'),
    'address' => env('LEGAL_ADDRESS', '86-90 Paul Street, London, EC2A 4NE, England'),
    'jurisdiction' => env('LEGAL_JURISDICTION', 'England and Wales'),

    /*
     * One address for everything a person can send us: contract questions,
     * data-subject requests, and complaints. A separate privacy@ mailbox is
     * only an improvement if somebody reads it, and one that bounces is a GDPR
     * problem rather than a tidiness one.
     */
    'contact_email' => env('LEGAL_CONTACT_EMAIL', 'legal@courtly.cloud'),

    'product' => env('LEGAL_PRODUCT', 'Avyo'),
    'site' => env('LEGAL_SITE', 'cm.avyo.ai'),

    /*
     * The UK regulator, because the controller is established in England and
     * the policy has to name where a complaint goes.
     */
    'supervisory_authority' => [
        'name' => "Information Commissioner's Office (ICO)",
        'url' => 'https://ico.org.uk',
        'helpline' => '0303 123 1113',
    ],

    /*
     * Shown as "Last updated" on each page. Bump the one you actually change:
     * a policy whose date moves when its text did not teaches readers that the
     * date means nothing.
     */
    'updated' => [
        'terms' => env('LEGAL_TERMS_UPDATED', '2026-08-27'),
        'privacy' => env('LEGAL_PRIVACY_UPDATED', '2026-08-27'),
        'cookies' => env('LEGAL_COOKIES_UPDATED', '2026-08-27'),
    ],

    /*
     * Bumping this re-asks everybody. It belongs to the *inventory* below, not
     * to the prose: fixing a typo in the cookie policy should not throw away a
     * million consent records, and adding an analytics cookie must.
     */
    'consent_version' => env('LEGAL_CONSENT_VERSION', '2026-08-27'),

    /*
     * Every cookie this application sets, by the category the banner offers.
     *
     * Four categories, but only two of them are a question.
     *
     * `essential` is not offered as a choice because none of it is a choice: a
     * session cookie on a product you have to sign in to is strictly necessary
     * within the meaning of PECR, and asking permission to keep somebody
     * signed in would be theatre.
     *
     * `preferences` is always on for the same reason and it is worth being
     * plain about why, because "functional cookies" is where consent banners
     * usually start lying. These two are written only as the direct result of
     * somebody operating the interface — changing the theme, collapsing the
     * navigation. They hold a word each, carry no identifier, are never read by
     * anyone but this application, and cannot follow a person anywhere. ICO's
     * strictly-necessary carve-out covers preferences the user themselves asked
     * for, and the alternative — a toggle that, switched off, silently keeps
     * writing them — is worse than not offering the toggle.
     *
     * `analytics` and `marketing` are genuinely opt-in, default to off, and are
     * deliberately empty today: Avyo runs no analytics, no tag manager and no
     * advertising pixel, and the policy says exactly that rather than reserving
     * the right in vague terms. They exist so that the day one is added,
     * consent for it has been collected already rather than retrofitted — see
     * resources/js/lib/consent.ts, which is the gate that makes them real.
     */
    'cookies' => [
        [
            'name' => 'avyo-session',
            'category' => 'essential',
            'provider' => 'Avyo (first party)',
            'purpose' => 'Keeps you signed in and ties your requests to your account.',
            'retention' => '2 hours of inactivity',
        ],
        [
            'name' => 'XSRF-TOKEN',
            'category' => 'essential',
            'provider' => 'Avyo (first party)',
            'purpose' => 'Proves a form was submitted from Avyo and not from another site (CSRF protection).',
            'retention' => '2 hours of inactivity',
        ],
        [
            'name' => 'avyo_consent',
            'category' => 'essential',
            'provider' => 'Avyo (first party)',
            'purpose' => 'Remembers the cookie choices you made here, so you are not asked on every page.',
            'retention' => '12 months',
        ],
        [
            'name' => 'appearance',
            'category' => 'preferences',
            'provider' => 'Avyo (first party)',
            'purpose' => 'Remembers whether you chose the light, dark, or system theme.',
            'retention' => '12 months',
        ],
        [
            'name' => 'sidebar_state',
            'category' => 'preferences',
            'provider' => 'Avyo (first party)',
            'purpose' => 'Remembers whether you collapsed the navigation column.',
            'retention' => '7 days',
        ],
    ],

    /*
     * Who else touches the data, and why. Named rather than described as
     * "trusted partners", because a data-subject asking where their draft went
     * is entitled to the list.
     *
     * `optional` marks a provider that only receives anything if a project
     * connects it or the deployment enables it — the Threads presence is off
     * by default (config/social.php), and Google is granted per project.
     */
    'subprocessors' => [
        [
            'name' => 'OpenAI',
            'purpose' => 'Generates and edits plans, drafts, replies, and embeddings from the material in your projects.',
            'region' => 'United States',
            'optional' => false,
        ],
        [
            'name' => 'AtlasCloud (Seedream)',
            'purpose' => 'Generates and edits the images attached to posts and articles.',
            'region' => 'United States',
            'optional' => false,
        ],
        [
            'name' => 'Google (Gemini), Anthropic (Claude), Perplexity',
            'purpose' => 'Answer the visibility prompts we run to measure whether your brand is cited in AI answers.',
            'region' => 'United States',
            'optional' => false,
        ],
        [
            'name' => 'Ahrefs, DataForSEO',
            'purpose' => 'Supply keyword demand and search-results data for planning. They receive search terms, not your account data.',
            'region' => 'Singapore, United States',
            'optional' => false,
        ],
        [
            'name' => 'Google (Search Console, Analytics 4, PageSpeed Insights)',
            'purpose' => 'Returns the performance of the site a project is connected to, once you grant access to it.',
            'region' => 'United States',
            'optional' => true,
        ],
        [
            'name' => 'Meta Platforms (Threads)',
            'purpose' => 'Publishes approved posts and delivers the replies and mentions your account receives.',
            'region' => 'United States',
            'optional' => true,
        ],
        [
            'name' => 'Hosting and infrastructure',
            'purpose' => 'Runs the application, its database, and its queues.',
            'region' => 'European Union',
            'optional' => false,
        ],
    ],
];
