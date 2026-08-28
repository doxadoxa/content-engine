import { Form, Head, Link, router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import {
    projects as projectsRoute,
    subscriptions as subscriptionsRoute,
} from '@/routes/admin';
import type { Paginated } from '@/types';

type Row = {
    id: string;
    project: string | null;
    project_id: string;
    slug: string | null;
    plan: string;
    price_cents: number;
    status: string;
    stripe_id: string | null;
    stripe_status: string | null;
    disagrees: boolean;
    payer: string | null;
    period_ends_at: string | null;
    trial_ends_at: string | null;
    grace_ends_at: string | null;
};

type Props = {
    status: string;
    currency: string;
    statuses: { value: string; label: string }[];
    subscriptions: Paginated<Row>;
};

/**
 * Every subscription, and where ours disagrees with Stripe's.
 *
 * That last column is why this is not the projects list sorted differently.
 * Entitlement is read from a local projection, and a webhook lost to a deploy
 * or a signature mismatch leaves a project silently entitled or silently
 * stopped — neither of which raises anything, because both look exactly like
 * normal operation.
 */
export default function AdminSubscriptions({
    status,
    currency,
    statuses,
    subscriptions,
}: Props) {
    const money = (cents: number) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency.toUpperCase(),
            maximumFractionDigits: 0,
        }).format(cents / 100);

    return (
        <>
            <Head title="Subscriptions" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Administration"
                    context={`${subscriptions.total} in total`}
                    title="Subscriptions"
                    description="What we believe, beside what Stripe last told us."
                />

                <div className="flex flex-wrap gap-2">
                    <FilterLink current={status} value="" label="All" />
                    {statuses.map((option) => (
                        <FilterLink
                            key={option.value}
                            current={status}
                            value={option.value}
                            label={option.label}
                        />
                    ))}
                </div>

                <Card
                    className={`${workspacePanelClass} gap-0 overflow-hidden p-0`}
                >
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Project</TableHead>
                                        <TableHead>Plan</TableHead>
                                        <TableHead>Ours</TableHead>
                                        <TableHead>Stripe&rsquo;s</TableHead>
                                        <TableHead>Ends</TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {subscriptions.data.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell>
                                                <Link
                                                    href={`${projectsRoute().url}/${row.project_id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {row.project ?? row.slug}
                                                </Link>
                                                <div className="text-xs text-muted-foreground">
                                                    {row.payer ?? 'no payer'}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {row.plan}
                                                <div className="text-xs text-muted-foreground tabular-nums">
                                                    {money(row.price_cents)}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        row.status === 'active'
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                >
                                                    {row.status.replaceAll(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {row.stripe_status ? (
                                                    <span
                                                        className={
                                                            row.disagrees
                                                                ? 'text-amber-600 dark:text-amber-400'
                                                                : 'text-muted-foreground'
                                                        }
                                                    >
                                                        {row.disagrees && (
                                                            <AlertTriangle
                                                                className="mr-1 inline size-3.5"
                                                                aria-label="Disagrees with what we believe"
                                                            />
                                                        )}
                                                        {row.stripe_status}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        no provider
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground tabular-nums">
                                                {(
                                                    row.grace_ends_at ??
                                                    row.trial_ends_at ??
                                                    row.period_ends_at
                                                )?.slice(0, 10) ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {row.stripe_id && (
                                                    <Form
                                                        action={`${subscriptionsRoute().url}/${row.id}/resync`}
                                                        method="post"
                                                    >
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                        >
                                                            Ask Stripe
                                                        </Button>
                                                    </Form>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <Pagination page={subscriptions} />
            </WorkspacePage>
        </>
    );
}

function FilterLink({
    current,
    value,
    label,
}: {
    current: string;
    value: string;
    label: string;
}) {
    return (
        <Button
            variant={current === value ? 'secondary' : 'outline'}
            size="sm"
            onClick={() =>
                router.get(
                    subscriptionsRoute().url,
                    value === '' ? {} : { status: value },
                    { preserveState: true, replace: true },
                )
            }
        >
            {label}
        </Button>
    );
}

AdminSubscriptions.layout = {
    breadcrumbs: [{ title: 'Subscriptions', href: subscriptionsRoute() }],
};
