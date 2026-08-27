/** The five things a plan counts. Mirrors `App\Billing\Metric`. */
export type BillingMetric =
    | 'articles'
    | 'social_posts'
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
    plan: { key: string; name: string; price_cents: number } | null;
    status: BillingStatus | null;
    may_generate: boolean;
    refusal: BillingRefusal | null;
    usage: Partial<Record<BillingMetric, BillingUsage>>;
    trial_ends_at: string | null;
    period_ends_at: string | null;
};
