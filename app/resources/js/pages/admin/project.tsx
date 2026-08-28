import { Form, Head } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { projects as projectsRoute } from '@/routes/admin';
import type { Billing } from '@/types/billing';

type Props = {
    project: {
        id: string;
        name: string;
        slug: string;
        website_url: string | null;
        status: string;
        weekly_target: number;
        locales: string[];
        created_at: string | null;
    };
    entitlement: Billing;
    subscription: {
        plan: string;
        plan_version: number;
        status: string;
        limit_overrides: Record<string, number | null>;
        period_started_at: string | null;
        period_ends_at: string | null;
        trial_ends_at: string | null;
        grace_ends_at: string | null;
        stripe_id: string | null;
        stripe_status: string | null;
        payer: string | null;
    } | null;
    spend: {
        pipeline_micros: number;
        assistant_micros: number;
        total_micros: number;
    };
    currency: string;
    plans: { key: string; name: string; price_cents: number }[];
    members: { id: number; name: string; email: string; role: string | null }[];
    actions: {
        id: string;
        action: string;
        actor: string;
        before: Record<string, unknown>;
        after: Record<string, unknown>;
        at: string | null;
    }[];
};

/**
 * One project, and the three things somebody actually does to a customer's
 * service.
 *
 * The spend panel is the one this screen exists for: it puts what a project
 * costs beside what it pays, which is the comparison that decides whether a
 * plan is priced right and the one no payment provider can make for us.
 */
export default function AdminProject({
    project,
    entitlement,
    subscription,
    spend,
    currency,
    plans,
    members,
    actions,
}: Props) {
    const money = (cents: number) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency.toUpperCase(),
            maximumFractionDigits: 2,
        }).format(cents / 100);

    const base = `${projectsRoute().url}/${project.id}`;
    const paying = entitlement.plan?.price_cents ?? 0;
    const costing = spend.total_micros / 10_000;

    return (
        <>
            <Head title={project.name} />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Administration"
                    context={project.website_url ?? project.slug}
                    title={project.name}
                    description="What this project is entitled to, what it has cost, and what was done to it."
                    actions={
                        <div className="flex items-center gap-2">
                            {/*
                             * Labelled, because the two are different things
                             * that are usually the same word: an engine that
                             * is running, and a subscription that is paid.
                             * Two bare "active" badges side by side read as
                             * one badge drawn twice.
                             */}
                            <Badge variant="outline">
                                Engine: {project.status}
                            </Badge>
                            {subscription && (
                                <Badge variant="secondary">
                                    Billing:{' '}
                                    {subscription.status.replaceAll('_', ' ')}
                                </Badge>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <Figure label="Pays" value={money(paying)} hint="a month" />
                    <Figure
                        label="Costs"
                        value={money(costing)}
                        hint="this month, both doors"
                    />
                    <Figure
                        label="Margin"
                        value={money(paying - costing)}
                        hint={
                            costing > paying
                                ? 'costs more than it pays'
                                : 'this month'
                        }
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Move to a plan
                            </CardTitle>
                            <CardDescription>
                                Counters reset. Any bespoke limits belonging to
                                the previous arrangement are cleared with it.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                action={`${base}/plan`}
                                method="post"
                                className="flex flex-wrap items-end gap-3"
                            >
                                {({ processing }) => (
                                    <>
                                        <div className="grid min-w-48 flex-1 gap-2">
                                            <Label htmlFor="plan">Plan</Label>
                                            <Select
                                                name="plan"
                                                defaultValue={
                                                    subscription?.plan ??
                                                    plans[0]?.key
                                                }
                                            >
                                                <SelectTrigger id="plan">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {plans.map((plan) => (
                                                        <SelectItem
                                                            key={plan.key}
                                                            value={plan.key}
                                                        >
                                                            {plan.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Assign
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Extend the trial
                            </CardTitle>
                            <CardDescription>
                                From today when it has already lapsed, so the
                                days given are days that can be used.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                action={`${base}/trial`}
                                method="post"
                                className="flex flex-wrap items-end gap-3"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid w-32 gap-2">
                                            <Label htmlFor="days">Days</Label>
                                            <Input
                                                id="days"
                                                name="days"
                                                type="number"
                                                min={1}
                                                max={60}
                                                defaultValue={7}
                                            />
                                            {errors.days && (
                                                <p className="text-xs text-destructive">
                                                    {errors.days}
                                                </p>
                                            )}
                                        </div>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Extend
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                </div>

                <Card className={workspacePanelClass}>
                    <CardHeader>
                        <CardTitle className="text-base">The engine</CardTitle>
                        <CardDescription>
                            Pausing stops scheduled work. Everything this
                            project has made stays readable either way.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-wrap items-center gap-3">
                        <Form action={`${base}/status`} method="post">
                            <input
                                type="hidden"
                                name="status"
                                value={
                                    project.status === 'active'
                                        ? 'paused'
                                        : 'active'
                                }
                            />
                            <Button type="submit" variant="outline">
                                {project.status === 'active'
                                    ? 'Pause the engine'
                                    : 'Start the engine'}
                            </Button>
                        </Form>
                        <p className="text-sm text-muted-foreground">
                            Writes {project.weekly_target} a week
                            {entitlement.plan
                                ? `, capped by ${entitlement.plan.name}`
                                : ''}
                            .
                        </p>
                    </CardContent>
                </Card>

                {subscription && (
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Subscription
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2 text-sm sm:grid-cols-2">
                            <Detail
                                label="Plan"
                                value={`${subscription.plan} (list v${subscription.plan_version})`}
                            />
                            <Detail
                                label="Payer"
                                value={subscription.payer ?? 'nobody yet'}
                            />
                            <Detail
                                label="Period"
                                value={`${subscription.period_started_at?.slice(0, 10) ?? '—'} → ${subscription.period_ends_at?.slice(0, 10) ?? '—'}`}
                            />
                            <Detail
                                label="At Stripe"
                                value={
                                    subscription.stripe_id
                                        ? [
                                              subscription.stripe_id,
                                              subscription.stripe_status,
                                          ]
                                              .filter(Boolean)
                                              .join(' · ')
                                        : 'nothing behind it'
                                }
                            />
                            <Detail
                                label="Trial ends"
                                value={
                                    subscription.trial_ends_at?.slice(0, 10) ??
                                    '—'
                                }
                            />
                            <Detail
                                label="Grace ends"
                                value={
                                    subscription.grace_ends_at?.slice(0, 10) ??
                                    '—'
                                }
                            />
                        </CardContent>
                    </Card>
                )}

                <Card className={workspacePanelClass}>
                    <CardHeader>
                        <CardTitle className="text-base">Members</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-1 text-sm">
                        {members.map((member) => (
                            <div
                                key={member.id}
                                className="flex flex-wrap items-baseline gap-2"
                            >
                                <span>{member.name}</span>
                                <span className="text-muted-foreground">
                                    {member.email}
                                </span>
                                <Badge variant="outline" className="ml-auto">
                                    {member.role ?? 'member'}
                                </Badge>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card className={workspacePanelClass}>
                    <CardHeader>
                        <CardTitle className="text-base">
                            What was done here
                        </CardTitle>
                        <CardDescription>
                            Every administrative change, with what it changed.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {actions.length === 0 && (
                            <p className="text-muted-foreground">
                                Nothing yet.
                            </p>
                        )}
                        {actions.map((action) => (
                            <div
                                key={action.id}
                                className="border-b pb-2 last:border-0"
                            >
                                <div className="flex flex-wrap items-baseline gap-2">
                                    <span className="font-medium">
                                        {action.action}
                                    </span>
                                    <span className="text-muted-foreground">
                                        by {action.actor}
                                    </span>
                                    <span className="ml-auto text-muted-foreground tabular-nums">
                                        {action.at
                                            ?.slice(0, 16)
                                            .replace('T', ' ')}
                                    </span>
                                </div>
                                <p className="mt-1 font-mono text-xs text-muted-foreground">
                                    {String(action.before.plan ?? '—')} →{' '}
                                    {String(action.after.plan ?? '—')} ·{' '}
                                    {String(
                                        action.before.billing_status ?? '—',
                                    )}{' '}
                                    →{' '}
                                    {String(action.after.billing_status ?? '—')}
                                </p>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </WorkspacePage>
        </>
    );
}

function Figure({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint: string;
}) {
    return (
        <Card className={workspacePanelClass}>
            <CardContent className="pt-6">
                <p className="text-sm text-muted-foreground">{label}</p>
                <p className="mt-1 text-2xl font-semibold tabular-nums">
                    {value}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            </CardContent>
        </Card>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{value}</span>
        </div>
    );
}

AdminProject.layout = {
    breadcrumbs: [{ title: 'Projects', href: projectsRoute() }],
};
