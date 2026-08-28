import { Form, Head } from '@inertiajs/react';
import { Check, Infinity as InfinityIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { checkout, index, portal } from '@/routes/billing';
import type { Billing, BillingMetric, BillingUsage } from '@/types/billing';

type PlanLimit = { key: string; label: string; value: number | null };

type PlanCard = {
    key: string;
    name: string;
    price_cents: number;
    limits: PlanLimit[];
    current: boolean;
};

type Props = {
    entitlement: Billing;
    plans: PlanCard[];
    currency: string;
    trial_days: number;
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
    plans,
    currency,
    trial_days,
    can_pay,
    has_provider,
}: Props) {
    const money = (cents: number) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency.toUpperCase(),
            maximumFractionDigits: 0,
        }).format(cents / 100);

    return (
        <>
            <Head title="Plan & usage" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Administration"
                    title="Plan & usage"
                    description="What this project is allowed to make this period, and what it has made."
                    actions={
                        <div className="flex items-center gap-2">
                            {entitlement.plan && (
                                <Badge
                                    variant="outline"
                                    className="rounded-full px-3 py-2"
                                >
                                    {entitlement.plan.name}
                                </Badge>
                            )}
                            {can_pay && has_provider && (
                                <Form action={portal()} method="post">
                                    <Button type="submit" variant="outline">
                                        Manage billing
                                    </Button>
                                </Form>
                            )}
                        </div>
                    }
                />

                {entitlement.refusal && (
                    <Card
                        className={`${workspacePanelClass} border-amber-500/30 bg-amber-500/5`}
                    >
                        <CardHeader>
                            <CardTitle className="text-base">
                                The engine is not running
                            </CardTitle>
                            <CardDescription>
                                {entitlement.refusal.message} Everything this
                                project has already made stays here, and
                                anything approved is still being published.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                {entitlement.exhausted.length > 0 && !entitlement.refusal && (
                    <Card
                        className={`${workspacePanelClass} border-amber-500/30 bg-amber-500/5`}
                    >
                        <CardHeader>
                            <CardTitle className="text-base">
                                {entitlement.exhausted.length === 1
                                    ? 'One allowance is used up'
                                    : 'Some allowances are used up'}
                            </CardTitle>
                            <CardDescription>
                                The engine is still running everything else. A
                                larger plan raises these for the rest of the
                                period.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                <UsagePanel usage={entitlement.usage} />

                <div className="grid gap-4 lg:grid-cols-2">
                    {plans.map((plan) => (
                        <Card
                            key={plan.key}
                            className={`${workspacePanelClass} ${plan.current ? 'ring-2 ring-primary' : ''}`}
                        >
                            <CardHeader>
                                <div className="flex items-baseline justify-between gap-3">
                                    <CardTitle>{plan.name}</CardTitle>
                                    <span className="text-lg font-semibold tabular-nums">
                                        {money(plan.price_cents)}
                                        <span className="text-sm font-normal text-muted-foreground">
                                            /month
                                        </span>
                                    </span>
                                </div>
                                <CardDescription>
                                    {plan.current
                                        ? 'This project is on this plan.'
                                        : has_provider
                                          ? 'Switch to this plan. Stripe settles the difference on your next invoice.'
                                          : `Per project. ${trial_days} days free, then this.`}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ul className="space-y-2 text-sm">
                                    {plan.limits.map((limit) => (
                                        <li
                                            key={limit.key}
                                            className="flex items-center gap-2"
                                        >
                                            <Check
                                                className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                                                aria-hidden="true"
                                            />
                                            <span className="text-muted-foreground">
                                                {limit.label}
                                            </span>
                                            <span className="ml-auto font-medium tabular-nums">
                                                {limit.value === null ? (
                                                    <InfinityIcon
                                                        className="size-4"
                                                        aria-label="Unlimited"
                                                    />
                                                ) : (
                                                    limit.value
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>

                                {can_pay && !plan.current && (
                                    <Form
                                        action={checkout()}
                                        method="post"
                                        className="mt-5"
                                    >
                                        <input
                                            type="hidden"
                                            name="plan"
                                            value={plan.key}
                                        />
                                        <Button
                                            type="submit"
                                            className="w-full"
                                        >
                                            {has_provider
                                                ? `Switch to ${plan.name}`
                                                : `Choose ${plan.name}`}
                                        </Button>
                                    </Form>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
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
}: {
    usage: Partial<Record<BillingMetric, BillingUsage>>;
}) {
    const rows = Object.entries(usage) as [BillingMetric, BillingUsage][];

    if (rows.length === 0) {
        return null;
    }

    return (
        <Card className={workspacePanelClass}>
            <CardHeader>
                <CardTitle className="text-base">This period</CardTitle>
                <CardDescription>
                    Counted when work is approved, not when it is generated —
                    the engine writes several drafts to keep one, and you are
                    not charged for the ones it discards.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {rows.map(([metric, row]) => (
                    <div key={metric} className="space-y-1.5">
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span className="capitalize">
                                {metric.replaceAll('_', ' ')}
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                {row.limit === null
                                    ? `${row.used} used`
                                    : `${row.used} of ${row.limit}`}
                            </span>
                        </div>
                        {row.limit !== null && (
                            <div
                                className="h-1.5 overflow-hidden rounded-full bg-muted"
                                role="progressbar"
                                aria-valuenow={row.used}
                                aria-valuemin={0}
                                aria-valuemax={row.limit}
                                aria-label={`${metric.replaceAll('_', ' ')} used`}
                            >
                                <div
                                    className={`h-full rounded-full ${row.remaining === 0 ? 'bg-amber-500' : 'bg-primary'}`}
                                    style={{
                                        width: `${Math.min(100, (row.used / Math.max(1, row.limit)) * 100)}%`,
                                    }}
                                />
                            </div>
                        )}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

BillingPage.layout = {
    breadcrumbs: [{ title: 'Plan & usage', href: index() }],
};
