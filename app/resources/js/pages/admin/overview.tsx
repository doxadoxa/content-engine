import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Coins, TrendingUp, Users } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { overview, projects } from '@/routes/admin';

type Margin = {
    project_id: string;
    name: string;
    slug: string;
    plan: string;
    status: string;
    price_cents: number;
    cost_micros: number;
    ceiling_micros: number | null;
};

type Props = {
    month: string;
    counts: {
        projects: number;
        active: number;
        trialing: number;
        past_due: number;
        canceled: number;
    };
    revenue_cents: number;
    cost_micros: number;
    currency: string;
    margins: Margin[];
    recent_actions: {
        id: string;
        action: string;
        actor: string | null;
        project: string | null;
        at: string | null;
    }[];
};

/**
 * The business on one screen.
 *
 * The column worth having is margin, not MRR — Stripe shows revenue better
 * than we can, and only this application knows what a customer *cost*. Every
 * model call and every picture has been metered since the engine was built, so
 * the number is nearly free and it is the one that decides whether a plan is
 * priced right.
 */
export default function AdminOverview({
    counts,
    revenue_cents,
    cost_micros,
    currency,
    margins,
    recent_actions,
}: Props) {
    const money = (cents: number) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency.toUpperCase(),
            maximumFractionDigits: 0,
        }).format(cents / 100);

    const costCents = cost_micros / 10_000;
    const marginPct =
        revenue_cents === 0
            ? null
            : Math.round(((revenue_cents - costCents) / revenue_cents) * 100);

    return (
        <>
            <Head title="Service overview" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Administration"
                    context="This month"
                    title="Service overview"
                    description="What every project is paying, and what every project is costing."
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat
                        icon={Coins}
                        label="Revenue"
                        value={money(revenue_cents)}
                        hint={`${counts.active} paying`}
                    />
                    <Stat
                        icon={TrendingUp}
                        label="Cost"
                        value={money(costCents)}
                        hint="models, images, conversation"
                    />
                    <Stat
                        icon={TrendingUp}
                        label="Gross margin"
                        value={marginPct === null ? '—' : `${marginPct}%`}
                        hint="revenue less what it cost to serve"
                    />
                    <Stat
                        icon={Users}
                        label="Projects"
                        value={String(counts.projects)}
                        hint={`${counts.trialing} trialing · ${counts.past_due} past due`}
                    />
                </div>

                <Card
                    className={`${workspacePanelClass} gap-0 overflow-hidden p-0`}
                >
                    <CardHeader className="border-b px-5 py-5 sm:px-6">
                        <CardTitle className="text-base">
                            Worst margin first
                        </CardTitle>
                        <CardDescription>
                            The projects eating more than they pay for. No
                            generic billing dashboard can compute this — it
                            needs both halves.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Project</TableHead>
                                        <TableHead>Plan</TableHead>
                                        <TableHead className="text-right">
                                            Pays
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Costs
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Margin
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {margins.map((row) => {
                                        const rowCost =
                                            row.cost_micros / 10_000;
                                        const underwater =
                                            rowCost > row.price_cents;

                                        return (
                                            <TableRow key={row.project_id}>
                                                <TableCell>
                                                    <Link
                                                        href={`${projects().url}/${row.project_id}`}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {row.name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {row.plan}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {money(row.price_cents)}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {money(rowCost)}
                                                </TableCell>
                                                <TableCell
                                                    className={`text-right tabular-nums ${underwater ? 'text-amber-600 dark:text-amber-400' : ''}`}
                                                >
                                                    {underwater && (
                                                        <AlertTriangle
                                                            className="mr-1 inline size-3.5"
                                                            aria-label="Costs more than it pays"
                                                        />
                                                    )}
                                                    {money(
                                                        row.price_cents -
                                                            rowCost,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {recent_actions.length > 0 && (
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recently done
                            </CardTitle>
                            <CardDescription>
                                Every change an administrator made to somebody
                                else's service.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {recent_actions.map((action) => (
                                <div
                                    key={action.id}
                                    className="flex flex-wrap items-baseline gap-x-2 text-muted-foreground"
                                >
                                    <span className="font-medium text-foreground">
                                        {action.actor ?? 'a deleted account'}
                                    </span>
                                    <span>{action.action}</span>
                                    {action.project && (
                                        <span className="text-foreground">
                                            {action.project}
                                        </span>
                                    )}
                                    <span className="ml-auto tabular-nums">
                                        {action.at
                                            ?.slice(0, 16)
                                            .replace('T', ' ')}
                                    </span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </WorkspacePage>
        </>
    );
}

function Stat({
    icon: Icon,
    label,
    value,
    hint,
}: {
    icon: typeof Coins;
    label: string;
    value: string;
    hint: string;
}) {
    return (
        <Card className={workspacePanelClass}>
            <CardHeader className="pb-2">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Icon className="size-4" aria-hidden="true" />
                    {label}
                </div>
            </CardHeader>
            <CardContent>
                <p className="text-2xl font-semibold tabular-nums">{value}</p>
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            </CardContent>
        </Card>
    );
}

AdminOverview.layout = {
    breadcrumbs: [{ title: 'Service overview', href: overview() }],
};
