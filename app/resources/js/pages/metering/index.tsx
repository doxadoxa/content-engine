import { Head } from '@inertiajs/react';
import {
    CircleDollarSign,
    Coins,
    Gauge,
    ListTree,
    MessagesSquare,
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
    /** The second door (§3.3): what talking to the engine cost. */
    assistant: {
        turns: number;
        input_tokens: number;
        output_tokens: number;
        cost_micros: number;
        average_micros: number | null;
    };
    /** Both doors summed. The only figure that answers "what did this cost". */
    spend: {
        pipeline_micros: number;
        assistant_micros: number;
        total_micros: number;
    } | null;
};

const money = (micros: number): string => `$${(micros / 1_000_000).toFixed(4)}`;

/** Owner-facing usage and spend for the current project. */
export default function Metering({
    days,
    by_step,
    by_pipeline,
    trend,
    per_unit,
    assistant,
    spend,
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
                    description="Project model usage, assistant spend, automated workflow spend, and average cost per item worked on."
                    actions={
                        <Badge
                            variant="outline"
                            className="rounded-full px-3 py-2"
                        >
                            Owner only
                        </Badge>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat
                        icon={Coins}
                        iconClassName="bg-amber-500/10 text-amber-600 dark:text-amber-300"
                        label="Total spend"
                        value={money(
                            spend?.total_micros ??
                                by_pipeline.reduce(
                                    (sum, row) => sum + row.cost_micros,
                                    0,
                                ),
                        )}
                        hint="automated work and conversation together"
                    />
                    <Stat
                        icon={CircleDollarSign}
                        iconClassName="bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"
                        label="Per item worked on"
                        value={
                            per_unit.average_micros === null
                                ? '—'
                                : money(per_unit.average_micros)
                        }
                        hint={`${per_unit.units} item${per_unit.units === 1 ? '' : 's'} with automated work`}
                    />
                    <Stat
                        icon={MessagesSquare}
                        iconClassName="bg-sky-500/10 text-sky-600 dark:text-sky-300"
                        label="Assistant"
                        value={money(assistant.cost_micros)}
                        hint={
                            assistant.turns === 0
                                ? 'no conversation in this window'
                                : `${assistant.turns} turn${assistant.turns === 1 ? '' : 's'}, ${
                                      assistant.average_micros === null
                                          ? '—'
                                          : money(assistant.average_micros)
                                  } each`
                        }
                    />
                    <Stat
                        icon={ListTree}
                        iconClassName="bg-violet-500/10 text-violet-600 dark:text-violet-300"
                        label="Steps measured"
                        value={String(by_step.length)}
                        hint="distinct step keys"
                    />
                </div>

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
                                        workflow settings.
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
