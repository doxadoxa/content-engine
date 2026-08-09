import { Head } from '@inertiajs/react';
import {
    CircleDollarSign,
    Coins,
    Gauge,
    Layers,
    ListTree,
    TrendingUp,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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
import { index } from '@/routes/metering';

type StepRow = {
    pipeline: string;
    step_key: string;
    runs: number;
    input_tokens: number;
    output_tokens: number;
    cost_micros: number;
    latency_ms: number;
};

/** One of §8's four separate lines. */
type CostLine = {
    key: string;
    label: string;
    note: string;
    cost_micros: number;
    units: number;
    unit_label: string;
    per_unit_micros: number | null;
    /** Set for listening only: a standing cost is per day, never per post. */
    per_day_micros: number | null;
    per_post_micros: number | null;
    standing: boolean;
    answered?: number;
};

type SocialCost = {
    window_days: number;
    post: {
        published: number;
        cost_micros: number;
        average_micros: number | null;
        candidates: number;
        candidates_per_post: number | null;
        per_generation_micros: number | null;
    };
    article: {
        published: number;
        cost_micros: number;
        average_micros: number | null;
    };
    lines: CostLine[];
};

type Props = {
    days: number;
    by_step: StepRow[];
    by_pipeline: { pipeline: string; runs: number; cost_micros: number }[];
    trend: { day: string; cost_micros: number; runs: number }[];
    per_unit: {
        units: number;
        cost_micros: number;
        average_micros: number | null;
    };
    social: SocialCost | null;
};

const money = (micros: number): string => `$${(micros / 1_000_000).toFixed(4)}`;

/** Owner-facing usage and spend for the current project. */
export default function Metering({
    days,
    by_step,
    by_pipeline,
    trend,
    per_unit,
    social,
}: Props) {
    const peak = Math.max(1, ...trend.map((point) => point.cost_micros));

    return (
        <>
            <Head title="Usage & cost" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Administration"
                    context={`${days}-day window`}
                    title="Usage & cost"
                    description="Project-level model usage, interactive assistant spend, pipeline spend, and the cost of producing each content unit."
                    actions={
                        <Badge
                            variant="outline"
                            className="rounded-full px-3 py-2"
                        >
                            Owner only
                        </Badge>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <Stat
                        icon={CircleDollarSign}
                        iconClassName="bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"
                        label="Per content unit"
                        value={
                            per_unit.average_micros === null
                                ? '—'
                                : money(per_unit.average_micros)
                        }
                        hint={`${per_unit.units} unit${per_unit.units === 1 ? '' : 's'}`}
                    />
                    <Stat
                        icon={Coins}
                        iconClassName="bg-amber-500/10 text-amber-600 dark:text-amber-300"
                        label="Total spend"
                        value={money(
                            by_pipeline.reduce(
                                (sum, row) => sum + row.cost_micros,
                                0,
                            ),
                        )}
                        hint={`${by_pipeline.reduce((sum, row) => sum + row.runs, 0)} runs`}
                    />
                    <Stat
                        icon={ListTree}
                        iconClassName="bg-violet-500/10 text-violet-600 dark:text-violet-300"
                        label="Steps measured"
                        value={String(by_step.length)}
                        hint="distinct step keys"
                    />
                </div>

                {social && <PublishedPost social={social} />}

                <Card
                    className={`${workspacePanelClass} gap-0 overflow-hidden p-0`}
                >
                    <CardHeader className="border-b px-5 py-5 sm:px-6">
                        <div className="flex items-start gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-300">
                                <Gauge className="size-4" aria-hidden="true" />
                            </span>
                            <div>
                                <CardTitle className="text-base">
                                    Usage by model action
                                </CardTitle>
                                <CardDescription className="mt-1">
                                    Tokens, latency, and spend separated by
                                    workflow and step.
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>

                    <div className="overflow-x-auto">
                        <Table className="min-w-[780px]">
                            <TableHeader>
                                <TableRow className="bg-muted/20 text-xs tracking-wide uppercase">
                                    <TableHead>Workflow</TableHead>
                                    <TableHead>Step</TableHead>
                                    <TableHead>Runs</TableHead>
                                    <TableHead>Input</TableHead>
                                    <TableHead>Output</TableHead>
                                    <TableHead>Avg ms</TableHead>
                                    <TableHead>Cost</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {by_step.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="h-28 text-center text-muted-foreground"
                                        >
                                            No measured model usage in this
                                            window.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    by_step.map((row) => (
                                        <TableRow
                                            key={`${row.pipeline}:${row.step_key}`}
                                        >
                                            <TableCell className="text-muted-foreground">
                                                {row.pipeline.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {row.step_key}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.runs}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.input_tokens.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.output_tokens.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.latency_ms.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {money(row.cost_micros)}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </Card>

                {trend.length > 0 && (
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <div className="flex items-start gap-3">
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-300">
                                    <TrendingUp
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <CardTitle className="text-base">
                                        Daily spend
                                    </CardTitle>
                                    <CardDescription className="mt-1">
                                        Compare days before changing models or
                                        pipeline settings.
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div
                                className="flex h-36 items-end gap-1.5"
                                role="img"
                                aria-label={`Daily spend over ${days} days`}
                            >
                                {trend.map((point) => (
                                    <div
                                        key={point.day}
                                        className="flex-1 rounded-t-md bg-gradient-to-t from-violet-600 to-fuchsia-400"
                                        style={{
                                            height: `${Math.max(2, (point.cost_micros / peak) * 100)}%`,
                                        }}
                                        title={`${point.day}: ${money(point.cost_micros)}`}
                                    />
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </WorkspacePage>
        </>
    );
}

/**
 * §8 — the unit is a published post, not a generated one.
 *
 * The two headline figures sit next to each other on purpose: §12's sixth exit
 * criterion asks for the cost of a post to be *known and separated from* the
 * cost of an article, and separation you have to open a second screen to see is
 * not the useful kind.
 *
 * The four lines under them are §8's own list — listening, candidates, images,
 * replies. Listening reports a cost per day rather than a cost per post,
 * because it is hourly and constant: divide a fixed sweep by a fortnight that
 * published nothing and the per-post figure runs away to infinity while the
 * bill stays exactly the same.
 */
function PublishedPost({ social }: { social: SocialCost }) {
    const { post, article, lines } = social;

    return (
        <Card className={`${workspacePanelClass} gap-0 overflow-hidden p-0`}>
            <CardHeader className="border-b px-5 py-5 sm:px-6">
                <div className="flex items-start gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-sky-500/10 text-sky-600 dark:text-sky-300">
                        <Layers className="size-4" aria-hidden="true" />
                    </span>
                    <div>
                        <CardTitle className="text-base">
                            What a published post costs
                        </CardTitle>
                        <CardDescription className="mt-1">
                            The unit is the post that went out, with the
                            candidates that lost and the slots that came to
                            nothing folded into it. A report counting model
                            calls would be wrong by the selection ratio.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>

            <div className="grid gap-px border-b bg-border sm:grid-cols-2">
                <div className="bg-card px-5 py-5 sm:px-6">
                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                        Per published post
                    </p>
                    <p className="mt-2 text-2xl font-semibold tabular-nums">
                        {post.average_micros === null
                            ? '—'
                            : money(post.average_micros)}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {post.published === 0
                            ? 'Nothing published in this window, so there is nothing to divide by.'
                            : `${money(post.cost_micros)} over ${post.published} post${post.published === 1 ? '' : 's'}`}
                    </p>
                    {post.per_generation_micros !== null && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            One generation cost{' '}
                            {money(post.per_generation_micros)}
                            {post.candidates_per_post !== null &&
                                ` — ${post.candidates_per_post} were written for every post that went out.`}
                        </p>
                    )}
                </div>

                <div className="bg-card px-5 py-5 sm:px-6">
                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                        Per published article
                    </p>
                    <p className="mt-2 text-2xl font-semibold tabular-nums">
                        {article.average_micros === null
                            ? '—'
                            : money(article.average_micros)}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {article.published === 0
                            ? 'Nothing published in this window, so there is nothing to divide by.'
                            : `${money(article.cost_micros)} over ${article.published} article${article.published === 1 ? '' : 's'}`}
                    </p>
                </div>
            </div>

            <div className="overflow-x-auto">
                <Table className="min-w-[680px]">
                    <TableHeader>
                        <TableRow className="bg-muted/20 text-xs tracking-wide uppercase">
                            <TableHead>Line</TableHead>
                            <TableHead>Units</TableHead>
                            <TableHead>Per unit</TableHead>
                            <TableHead>Per post</TableHead>
                            <TableHead>Cost</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {lines.map((line) => (
                            <TableRow key={line.key}>
                                <TableCell className="max-w-[22rem] align-top">
                                    <span className="font-medium">
                                        {line.label}
                                    </span>
                                    <span className="mt-1 block text-xs leading-relaxed text-wrap text-muted-foreground">
                                        {line.note}
                                    </span>
                                </TableCell>
                                <TableCell className="align-top text-muted-foreground">
                                    {line.units.toLocaleString()}{' '}
                                    {line.unit_label}
                                </TableCell>
                                <TableCell className="align-top text-muted-foreground">
                                    {line.per_unit_micros === null
                                        ? '—'
                                        : money(line.per_unit_micros)}
                                </TableCell>
                                <TableCell className="align-top text-muted-foreground">
                                    {line.standing ? (
                                        <span title="Hourly and constant, so it is priced per day rather than per post.">
                                            {line.per_day_micros === null
                                                ? '—'
                                                : `${money(line.per_day_micros)}/day`}
                                        </span>
                                    ) : line.per_post_micros === null ? (
                                        '—'
                                    ) : (
                                        money(line.per_post_micros)
                                    )}
                                </TableCell>
                                <TableCell className="align-top font-medium">
                                    {money(line.cost_micros)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </Card>
    );
}

function Stat({
    icon: Icon,
    iconClassName,
    label,
    value,
    hint,
}: {
    icon: LucideIcon;
    iconClassName: string;
    label: string;
    value: string;
    hint: string;
}) {
    return (
        <Card className={`${workspacePanelClass} gap-3 py-5`}>
            <CardHeader className="gap-3 px-5">
                <span
                    className={`flex size-9 items-center justify-center rounded-xl ${iconClassName}`}
                >
                    <Icon className="size-4" aria-hidden="true" />
                </span>
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-2xl tabular-nums">{value}</CardTitle>
            </CardHeader>
            <CardContent className="px-5 text-xs text-muted-foreground">
                {hint}
            </CardContent>
        </Card>
    );
}

Metering.layout = {
    breadcrumbs: [{ title: 'Usage & cost', href: index() }],
};
