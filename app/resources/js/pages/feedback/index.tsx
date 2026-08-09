import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Eye, FileSearch, Quote, RefreshCw } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Pagination } from '@/components/pagination';
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
import { index } from '@/routes/feedback';
import type { Paginated } from '@/types';

type Unit = {
    id: string;
    title: string;
    state: string;
    public_url: string | null;
    impressions: number;
    clicks: number;
    indexed: boolean;
    cited: string[];
    citations_checked_at: string | null;
    refresh_due: boolean;
    refresh_reason: string | null;
};

type Props = {
    units: Paginated<Unit>;
    refresh_queue: Pick<Unit, 'id' | 'title' | 'refresh_reason'>[];
    summary: {
        live: number;
        refreshing: number;
        cited: number;
        unchecked: number;
    };
    trend: { day: string; impressions: number; clicks: number }[];
};

/**
 * What happened after publication (§9.6).
 *
 * The refresh queue is at the top rather than in a tab: a unit the loop has
 * flagged is the only thing on this screen that asks somebody to do something.
 */
export default function Feedback({
    units,
    refresh_queue,
    summary,
    trend,
}: Props) {
    const peak = Math.max(1, ...trend.map((point) => point.impressions));

    return (
        <>
            <Head title="Feedback" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Performance loop"
                    context={`${summary.live} live`}
                    title="Feedback"
                    description="What published work earned, where it was cited, and which articles now need another pass."
                />

                <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <Stat
                        icon={CheckCircle2}
                        label="Live"
                        value={summary.live}
                    />
                    <Stat
                        icon={RefreshCw}
                        label="Needs refresh"
                        value={summary.refreshing}
                        tone="orange"
                    />
                    <Stat
                        icon={Quote}
                        label="Cited somewhere"
                        value={summary.cited}
                        tone="violet"
                    />
                    <Stat
                        icon={FileSearch}
                        label="Not checked yet"
                        value={summary.unchecked}
                    />
                </div>

                {refresh_queue.length > 0 && (
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <RefreshCw
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Refresh queue
                            </CardTitle>
                            <CardDescription>
                                These decayed against their own earlier
                                performance. A rewrite goes back through
                                approval before it goes back to readers.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            {refresh_queue.map((unit) => (
                                <Link
                                    key={unit.id}
                                    href={`/content/${unit.id}`}
                                    className="flex flex-col gap-1 rounded-xl border bg-background/35 p-3 text-sm transition-colors hover:border-violet-500/30 hover:bg-violet-500/[0.035] sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <span className="font-medium">
                                        {unit.title}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {unit.refresh_reason}
                                    </span>
                                </Link>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {trend.length > 0 && (
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Impressions
                            </CardTitle>
                            <CardDescription>
                                Across everything live, per day.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div
                                className="flex h-36 items-end gap-1 rounded-2xl bg-muted/15 px-3 pt-3"
                                role="img"
                                aria-label="Daily search impressions trend"
                            >
                                {trend.map((point) => (
                                    <div
                                        key={point.day}
                                        className="min-w-1 flex-1 rounded-t bg-gradient-to-t from-violet-600/70 to-orange-400/80"
                                        style={{
                                            height: `${Math.max(2, (point.impressions / peak) * 100)}%`,
                                        }}
                                        title={`${point.day}: ${point.impressions.toLocaleString()}`}
                                    />
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {units.data.length === 0 ? (
                    <EmptyFeedback />
                ) : (
                    <>
                        <div className="flex flex-col gap-3 sm:hidden">
                            {units.data.map((unit) => (
                                <FeedbackMobileCard key={unit.id} unit={unit} />
                            ))}
                        </div>

                        <Card
                            className={`${workspacePanelClass} hidden max-w-full overflow-hidden p-0 sm:block`}
                        >
                            <Table className="min-w-[760px]">
                                <TableHeader>
                                    <TableRow className="bg-muted/20 text-xs tracking-wide uppercase">
                                        <TableHead>Unit</TableHead>
                                        <TableHead>Indexed</TableHead>
                                        <TableHead>Impressions</TableHead>
                                        <TableHead>Clicks</TableHead>
                                        <TableHead>Cited in</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {units.data.map((unit) => (
                                        <TableRow key={unit.id}>
                                            <TableCell className="max-w-sm">
                                                <Link
                                                    href={`/content/${unit.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {unit.title}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        unit.indexed
                                                            ? 'outline'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {unit.indexed
                                                        ? 'Yes'
                                                        : 'No'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {unit.impressions.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {unit.clicks.toLocaleString()}
                                            </TableCell>
                                            <TableCell>
                                                {unit.citations_checked_at ===
                                                null ? (
                                                    <span className="text-sm text-muted-foreground">
                                                        not checked
                                                    </span>
                                                ) : unit.cited.length === 0 ? (
                                                    <span className="text-sm text-muted-foreground">
                                                        nowhere
                                                    </span>
                                                ) : (
                                                    <div className="flex flex-wrap gap-1">
                                                        {unit.cited.map(
                                                            (assistant) => (
                                                                <Badge
                                                                    key={
                                                                        assistant
                                                                    }
                                                                >
                                                                    {assistant}
                                                                </Badge>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    </>
                )}

                <Pagination page={units} />
            </WorkspacePage>
        </>
    );
}

function FeedbackMobileCard({ unit }: { unit: Unit }) {
    return (
        <Link
            href={`/content/${unit.id}`}
            className="group flex min-w-0 flex-col gap-4 rounded-[1.25rem] border bg-card/80 p-4 shadow-sm backdrop-blur-sm transition-all hover:-translate-y-0.5 hover:border-violet-500/35 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <div className="flex min-w-0 items-start justify-between gap-3">
                <p className="leading-snug font-medium break-words group-hover:underline">
                    {unit.title}
                </p>
                <Badge
                    variant={unit.indexed ? 'outline' : 'secondary'}
                    className="rounded-full"
                >
                    {unit.indexed ? 'Indexed' : 'Not indexed'}
                </Badge>
            </div>
            <dl className="grid grid-cols-2 gap-3 border-t pt-3 text-sm">
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Impressions
                    </dt>
                    <dd className="mt-0.5 font-semibold tabular-nums">
                        {unit.impressions.toLocaleString()}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">Clicks</dt>
                    <dd className="mt-0.5 font-semibold tabular-nums">
                        {unit.clicks.toLocaleString()}
                    </dd>
                </div>
            </dl>
            <div className="flex flex-wrap gap-1.5">
                {unit.citations_checked_at === null ? (
                    <Badge variant="secondary" className="rounded-full">
                        Citations not checked
                    </Badge>
                ) : unit.cited.length === 0 ? (
                    <Badge variant="outline" className="rounded-full">
                        Not cited
                    </Badge>
                ) : (
                    unit.cited.map((assistant) => (
                        <Badge key={assistant} className="rounded-full">
                            {assistant}
                        </Badge>
                    ))
                )}
            </div>
        </Link>
    );
}

function Stat({
    icon: Icon,
    label,
    value,
    tone = 'neutral',
}: {
    icon: LucideIcon;
    label: string;
    value: number;
    tone?: 'neutral' | 'violet' | 'orange';
}) {
    const iconTone = {
        neutral: 'bg-muted text-muted-foreground',
        violet: 'bg-violet-500/10 text-violet-600 dark:text-violet-300',
        orange: 'bg-orange-500/10 text-orange-600 dark:text-orange-300',
    }[tone];

    return (
        <Card className={`${workspacePanelClass} gap-3 py-4 sm:py-5`}>
            <CardHeader className="gap-3 px-4 sm:px-5">
                <span
                    className={`flex size-8 items-center justify-center rounded-full ${iconTone}`}
                >
                    <Icon className="size-4" aria-hidden="true" />
                </span>
                <div>
                    <CardTitle className="text-2xl tabular-nums">
                        {value}
                    </CardTitle>
                    <CardDescription className="mt-0.5 text-xs">
                        {label}
                    </CardDescription>
                </div>
            </CardHeader>
        </Card>
    );
}

function EmptyFeedback() {
    return (
        <Card className={`${workspacePanelClass} py-12`}>
            <CardHeader className="items-center text-center">
                <span className="flex size-12 items-center justify-center rounded-2xl bg-violet-500/10 text-violet-600 dark:text-violet-300">
                    <Eye className="size-6" aria-hidden="true" />
                </span>
                <CardTitle>No published feedback yet</CardTitle>
                <CardDescription>
                    Search and citation results appear here after articles go
                    live and the first feedback sweep runs.
                </CardDescription>
            </CardHeader>
        </Card>
    );
}

Feedback.layout = {
    breadcrumbs: [{ title: 'Feedback', href: index() }],
};
