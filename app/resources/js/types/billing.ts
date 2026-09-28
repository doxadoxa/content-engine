/** The things a plan counts. Mirrors `App\Billing\Metric`. */
export type BillingMetric =
    | 'ai_answers'
    | 'page_improvements'
    | 'articles'
    | 'site_audits'
    | 'content_plans'
    | 'assistant_turns';

export type BillingStatus = 'active' | 'trialing' | 'past_due' | 'canceled';

/**
 * Why the engine will not spend, and which quota ran out.
 *
 * The code matters more than the message: each reason needs a different button
 * under it, and offering an upgrade to somebody whose card just failed is the
 * wrong one.
 */
export type BillingRefusal = {
    code:
        | 'no_subscription'
        | 'preview_finished'
        | 'trial_ended'
        | 'canceled'
        | 'past_due'
        | 'quota'
        | 'cost_ceiling';
    message: string;
    metric: BillingMetric | null;
};

/** `null` limit is unlimited, and is never the same thing as zero. */
export type BillingUsage = {
    used: number;
    limit: number | null;
    remaining: number | null;
};

/**
 * What the current project may do, shared on every page.
 *
 * Null when there is no project to say it about — a guest, or somebody still
 * inside the onboarding wizard.
 */
export type Billing = {
    /**
     * The card-free sample: a month's plan and one article, made before
     * anybody is asked for a card. Named by the server rather than inferred
     * from the plan key, so the rule lives where the bounds do.
     */
    preview: boolean;
    preview_finished: boolean;
    plan: {
        key: string;
        name: string;
        price_cents: number;
        currency: string;
    } | null;
    status: BillingStatus | null;
    may_generate: boolean;
    refusal: BillingRefusal | null;
    usage: Partial<Record<BillingMetric, BillingUsage>>;
    /**
     * The quotas with nothing left in them.
     *
     * Separate from `refusal` because running out of articles is not a global
     * refusal — the engine keeps improving pages and running audits — but it
     * is still something the operator has to be told, and `may_generate` alone
     * cannot say it.
     */
    exhausted: BillingMetric[];
    /**
     * The part of `exhausted` worth warning about.
     *
     * Leaves out what the engine does once a period by itself (the content
     * plan and the site audit), where a used-up counter means the work got
     * done, and is empty during a trial that converts to a plan already
     * chosen, whose allowance is sample-sized on purpose. Decided on the
     * server, so the banner and the billing page cannot disagree.
     */
    shortfalls: BillingMetric[];
    /**
     * A trial of a plan the customer already picked, set to start by itself.
     *
     * False for the legacy bare trial (plan key `trial`, nothing chosen) and
     * for a trial cancelled before it ends. When true there is nothing left
     * to choose, so nothing should ask them to.
     */
    converts_after_trial: boolean;
    trial_ends_at: string | null;
    period_ends_at: string | null;
};
