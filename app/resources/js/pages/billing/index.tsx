import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { index } from '@/routes/billing';
import type { Billing, BillingMetric, BillingUsage } from '@/types/billing';
import { allowanceDefinitions, allowanceLabel } from './allowances';
import { CurrentPlan } from './current-plan';
import type { SubscriptionDetails } from './current-plan';
import { PlanComparison } from './plan-comparison';
import type { PlanCard } from './plan-comparison';

type Props = {
    entitlement: Billing;
    subscription_details: SubscriptionDetails;
    plans: PlanCard[];
    currency: string;
    trial_days: number;
    pending_change: { name: string; effective_at: string | null } | null;
    /**
     * Whether the viewer may commit the account holder's card. Reading which
     * quotas are left is an operator's business; spending is not.
     */
    can_pay: boolean;
    /** False until a subscription exists at the provider to manage. */
    has_provider: boolean;
};

/**
 * One screen for both jobs: what you are using, and what else you could be on.
 *
 * They are the same four facts shown to the same person, and splitting them
 * would mean the version somebody met first depended on whether their trial
 * had already run out — meeting the pricing page for the first time at the
 * moment the engine stops.
 */
export default function BillingPage({
    entitlement,
    subscription_details,
    plans,
    currency,
    trial_days,
    pending_change,
    can_pay,
    has_provider,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const money = (cents: number, planCurrency: string = currency) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: planCurrency.toUpperCase(),
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        })
            .formatToParts(cents / 100)
            .map((part) =>
                part.type === 'currency' && planCurrency.toLowerCase() === 'usd'
                    ? 'US$'
                    : part.value,
            )
            .join('');

    const exhausted = entitlement.exhausted.filter(
        (metric) =>
            metric !== 'social_posts' &&
            (entitlement.usage[metric]?.limit ?? 1) !== 0,
    );

    return (
        <>
            <Head title="Plan & usage" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Your subscription"
                    title="Plan & usage"
                    description="Your current plan, remaining allowances, and billing details."
                />

                <CurrentPlan
                    entitlement={entitlement}
                    details={subscription_details}
                    canPay={can_pay}
                    hasProvider={has_provider}
                    money={money}
                />

                {errors.plan && (
                    <p role="alert" className="text-sm text-destructive">
                        {errors.plan}
                    </p>
                )}
                {entitlement.refusal && entitlement.plan && (
                    <Card
                        className={`${workspacePanelClass} border-amber-500/30 bg-amber-500/5`}
                    >
                        <CardHeader>
                            <CardTitle className="text-base">
                                New content is paused
                            </CardTitle>
                            <CardDescription>
                                {entitlement.refusal.message} Everything this
                                project has already made stays here, and
                                approved work follows the publication access
                                shown by your plan.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                {exhausted.length > 0 && !entitlement.refusal && (
                    <Card
                        className={`${workspacePanelClass} border-amber-500/30 bg-amber-500/5`}
                    >
                        <CardHeader>
                            <CardTitle className="text-base">
                                {exhausted.length === 1
                                    ? 'One allowance is used up'
                                    : 'Some allowances are used up'}
                            </CardTitle>
                            <CardDescription>
                                Other included features remain available.
                                Compare plans below for more capacity.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                {pending_change && (
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {pending_change.name} starts at renewal
                            </CardTitle>
                            <CardDescription>
                                {pending_change.effective_at
                                    ? new Date(
                                          pending_change.effective_at,
                                      ).toLocaleDateString()
                                    : 'At the end of this billing period'}
                                . Your current plan stays active until then.
                            </CardDescription>
                            {can_pay && (
                                <Form
                                    action="/billing/cancel-change"
                                    method="post"
                                >
                                    <Button variant="outline" type="submit">
                                        Keep my current plan
                                    </Button>
                                </Form>
                            )}
                        </CardHeader>
                    </Card>
                )}

                <UsagePanel
                    usage={entitlement.usage}
                    version={entitlement.plan?.version ?? 0}
                />

                <PlanComparison
                    plans={plans}
                    entitlement={entitlement}
                    details={subscription_details}
                    hasProvider={has_provider}
                    canPay={can_pay}
                    trialDays={trial_days}
                    money={money}
                />

                <details
                    className={`${workspacePanelClass} p-5 text-sm leading-6`}
                >
                    <summary className="cursor-pointer font-medium">
                        How allowances work
                    </summary>
                    <div className="mt-4 space-y-3 text-muted-foreground">
                        <p>
                            Articles count once when approved by you or by
                            automatic quality checks. Revisions and publication
                            retries for the same article use no extra allowance.
                        </p>
                        <p>
                            A page improvement counts the first time you accept
                            a proposal. Revisions and retries are included.
                            Rejected proposals use no allowance.
                        </p>
                        <p>
                            Article and service allowances apply to each billing
                            period. Team members, languages, monitored pages,
                            and website connections are limits on your project
                            setup.
                        </p>
                        {entitlement.plan?.version === 2 && (
                            <p>
                                Your earlier page-improvement package does not
                                include article creation. Starter and Growth
                                include it.
                            </p>
                        )}
                    </div>
                </details>
                {can_pay && (
                    <Link
                        href="/delivery-economics"
                        className="text-xs text-muted-foreground underline"
                    >
                        Delivery cost details
                    </Link>
                )}
            </WorkspacePage>
        </>
    );
}

/**
 * What has been used, as bars.
 *
 * An unlimited allowance draws no bar. A progress bar that can never fill is a
 * decoration, and one drawn at some arbitrary width is a lie about a ceiling
 * that does not exist.
 */
function UsagePanel({
    usage,
    version,
}: {
    usage: Partial<Record<BillingMetric, BillingUsage>>;
    version: number;
}) {
    const rows = Object.entries(usage).filter(
        ([key, row]) =>
            key !== 'social_posts' &&
            key !== 'articles' &&
            (key !== 'page_improvements' || version >= 2 || row.used > 0) &&
            (key !== 'ai_answers' || version >= 4) &&
            (row.limit !== 0 || row.used > 0),
    ) as [BillingMetric, BillingUsage][];

    if (rows.length === 0) {
        return null;
    }

    return (
        <details
            className={`${workspacePanelClass} p-5 sm:p-6`}
            id="other-usage"
        >
            <summary className="cursor-pointer font-medium">
                Other included usage
            </summary>
            <div className="mt-5 grid gap-5 sm:grid-cols-2">
                {rows.map(([metric, row]) => (
                    <div key={metric} className="space-y-2" data-usage={metric}>
                        <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                            <span>{allowanceLabel(metric)}</span>
                            <span className="text-muted-foreground tabular-nums">
                                {row.limit === null
                                    ? `${row.used} used · Unlimited`
                                    : `${row.used} of ${row.limit} · ${row.remaining} left`}
                            </span>
                        </div>
                        {row.limit !== null && row.limit > 0 && (
                            <div
                                className="h-1.5 overflow-hidden rounded-full bg-muted"
                                role="progressbar"
                                aria-valuenow={Math.min(row.used, row.limit)}
                                aria-valuemin={0}
                                aria-valuemax={row.limit}
                                aria-label={`${allowanceLabel(metric)} used`}
                            >
                                <div
                                    className={`h-full rounded-full ${row.remaining === 0 ? 'bg-amber-500' : 'bg-primary'}`}
                                    style={{
                                        width: `${Math.min(100, (row.used / Math.max(1, row.limit)) * 100)}%`,
                                    }}
                                />
                            </div>
                        )}
                        {allowanceDefinitions[metric] && (
                            <p className="text-sm leading-6 text-muted-foreground">
                                {allowanceDefinitions[metric]}
                            </p>
                        )}
                    </div>
                ))}
            </div>
        </details>
    );
}

BillingPage.layout = {
    breadcrumbs: [{ title: 'Plan & usage', href: index() }],
};
