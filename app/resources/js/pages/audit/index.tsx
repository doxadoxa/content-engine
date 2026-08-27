import { Form, Head } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    CircleCheck,
    ExternalLink,
    Gauge,
    RefreshCw,
    Sparkles,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
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
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { fixPlan, index, recheck } from '@/routes/audit';

type Severity = 'high' | 'medium' | 'low';

type Issue = {
    id: string;
    check: string;
    label: string;
    severity: Severity;
    severity_label: string;
    summary: string;
    detail: Record<string, unknown>;
};

type Page = {
    id: string;
    url: string;
    path: string;
    score: number | null;
    status_code: number | null;
    issues_count: number;
    response_ms: number | null;
    speed_score: number | null;
    issues: Issue[];
};

type Props = {
    audit: {
        id: string;
        health_score: number | null;
        seo_score: number | null;
        geo_score: number | null;
        speed_score: number | null;
        pages_crawled: number;
        issues_found: number;
        pages_with_issues: number;
        finished_at: string | null;
        finished_on: string | null;
        site_checks: {
            key: string;
            label: string;
            ok: boolean;
            severity: Severity | null;
            summary: string | null;
        }[];
        fix_plan: string | null;
        fix_plan_written_on: string | null;
    } | null;
    pages: Page[];
    groups: { key: string; label: string; score: number | null }[];
    checks: {
        key: string;
        label: string;
        description: string;
        group: string;
        group_label: string;
        scope: string;
    }[];
    is_running: boolean;
    is_writing_fix_plan: boolean;
    fix_plan_run: {
        status: string;
        running_for_minutes: number | null;
        error: string | null;
    } | null;
    project: { name: string; website_url: string | null };
};

/** After this, a plan that is still "writing" is worth remarking on. */
const FIX_PLAN_SLOW_AFTER_MINUTES = 3;

const SEVERITY_TONE: Record<Severity, string> = {
    high: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
    medium: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    low: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
};

/**
 * What the project's own site looks like to a crawler.
 *
 * Null and zero are rendered differently everywhere on this screen, and that is
 * the rule the whole layout is arranged around. A score of nothing is a site
 * that was measured and found wanting; a score of null is a site nobody
 * measured — a sweep that has never run, or a section with no readable page
 * behind it. They look alike in a number and mean opposite things, so null is
 * always an em dash and never a bar at zero.
 */
export default function SiteAudit({
    audit,
    pages,
    groups,
    checks,
    is_running,
    is_writing_fix_plan,
    fix_plan_run,
    project,
}: Props) {
    const withIssues = pages.filter((page) => page.issues_count > 0);

    return (
        <>
            <Head title="Site audit" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Site health"
                    context={
                        audit?.finished_on
                            ? `Read ${audit.finished_on}`
                            : 'Never read'
                    }
                    title="Site audit"
                    description="How the site reads to a search crawler and to an AI assistant, page by page."
                    actions={
                        <>
                            <Form
                                action={recheck().url}
                                method="post"
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing || is_running}
                                    >
                                        <RefreshCw
                                            className={
                                                is_running
                                                    ? 'size-4 animate-spin'
                                                    : 'size-4'
                                            }
                                            aria-hidden="true"
                                        />
                                        {is_running
                                            ? 'Reading the site…'
                                            : 'Recheck'}
                                    </Button>
                                )}
                            </Form>
                            {audit !== null && (
                                <Form
                                    action={fixPlan().url}
                                    method="post"
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                is_writing_fix_plan
                                            }
                                        >
                                            <Sparkles
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {is_writing_fix_plan
                                                ? 'Writing…'
                                                : 'Get AI fix plan'}
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </>
                    }
                />

                {audit === null ? (
                    <EmptyState running={is_running} project={project} />
                ) : (
                    <AuditHero
                        audit={audit}
                        groups={groups}
                        running={is_running}
                        project={project}
                    />
                )}

                {audit !== null && audit.site_checks.length > 0 && (
                    <Card className={workspacePanelClass}>
                        <CardHeader className="border-b pb-5">
                            <CardTitle className="text-sm">
                                Site-wide checks
                            </CardTitle>
                            <CardDescription>
                                These are true of the whole site rather than of
                                any one page, so a fault here affects everything
                                below it.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 pt-5 sm:grid-cols-2">
                            {audit.site_checks.map((check) => (
                                <div
                                    key={check.key}
                                    className="flex items-start justify-between gap-3 rounded-2xl border bg-muted/20 p-4"
                                >
                                    <div className="min-w-0">
                                        <div className="text-sm font-medium">
                                            {check.label}
                                        </div>
                                        {check.summary && (
                                            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                                {check.summary}
                                            </p>
                                        )}
                                    </div>
                                    {check.ok ? (
                                        <Badge
                                            variant="secondary"
                                            className="shrink-0 bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                                        >
                                            <CircleCheck
                                                className="size-3"
                                                aria-hidden="true"
                                            />
                                            Ok
                                        </Badge>
                                    ) : (
                                        <Badge
                                            variant="secondary"
                                            className={`shrink-0 ${SEVERITY_TONE[check.severity ?? 'low']}`}
                                        >
                                            {check.severity ?? 'issue'}
                                        </Badge>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {audit !== null && !audit.fix_plan && (
                    <FixPlanState
                        run={fix_plan_run}
                        writing={is_writing_fix_plan}
                    />
                )}

                {audit?.fix_plan && (
                    <Card className={workspacePanelClass}>
                        <CardHeader className="border-b pb-5">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <span className="flex size-8 items-center justify-center rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300">
                                    <Sparkles
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </span>
                                Fix plan
                            </CardTitle>
                            <CardDescription>
                                Written {audit.fix_plan_written_on} from this
                                sweep, ordered by what costs this site the most.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="pt-5">
                            <div
                                className="prose prose-sm max-w-none dark:prose-invert"
                                // Rendered and sanitised server-side through
                                // SafeMarkdown, which strips HTML input — the
                                // same path every other stretch of model prose
                                // in this application takes to a screen.
                                dangerouslySetInnerHTML={{
                                    __html: audit.fix_plan,
                                }}
                            />
                        </CardContent>
                    </Card>
                )}

                {audit !== null && (
                    <Card className={workspacePanelClass}>
                        <CardHeader className="border-b pb-5">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <span className="flex size-8 items-center justify-center rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-300">
                                    <TriangleAlert
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </span>
                                Issues detected
                            </CardTitle>
                            <CardDescription>
                                {audit.issues_found === 0
                                    ? `Nothing wrong across ${audit.pages_crawled} pages.`
                                    : `${audit.issues_found} across ${audit.pages_crawled} pages. Worst first.`}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="pt-5">
                            {withIssues.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Every page read cleanly.
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {withIssues.map((page) => (
                                        <PageRow key={page.id} page={page} />
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                )}

                <ChecksPanel checks={checks} />
            </WorkspacePage>
        </>
    );
}

function AuditHero({
    audit,
    groups,
    running,
    project,
}: {
    audit: NonNullable<Props['audit']>;
    groups: Props['groups'];
    running: boolean;
    project: Props['project'];
}) {
    // Null is unmeasured; zero is a real result and must remain visible.
    const measured = audit.health_score !== null;

    return (
        <section
            className="relative isolate overflow-hidden rounded-[1.75rem] border border-white/8 bg-[#17141f] text-white shadow-[0_24px_80px_rgba(26,20,43,0.22)]"
            aria-labelledby="audit-overview"
        >
            <div
                className="pointer-events-none absolute -top-28 -right-24 -z-10 size-80 rounded-full bg-emerald-500/25 blur-3xl"
                aria-hidden="true"
            />
            <div
                className="pointer-events-none absolute -bottom-32 left-16 -z-10 size-72 rounded-full bg-orange-500/15 blur-3xl"
                aria-hidden="true"
            />

            <div className="flex h-full flex-col p-6 sm:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2
                            id="audit-overview"
                            className="flex items-center gap-2 text-xs font-medium tracking-[0.14em] text-emerald-200 uppercase"
                        >
                            <Gauge className="size-3.5" aria-hidden="true" />
                            Health score
                        </h2>
                        <p className="mt-2 text-sm text-white/50">
                            {running
                                ? 'A new reading is in progress.'
                                : `Last read ${audit.finished_on ?? 'never'}`}
                        </p>
                    </div>
                    {project.website_url && (
                        <a
                            href={project.website_url}
                            target="_blank"
                            rel="noreferrer noopener"
                            className="flex items-center gap-1.5 rounded-full border border-white/10 bg-white/8 px-3 py-1 text-xs text-white/80 hover:bg-white/12"
                        >
                            {project.website_url.replace(/^https?:\/\//, '')}
                            <ExternalLink
                                className="size-3"
                                aria-hidden="true"
                            />
                        </a>
                    )}
                </div>

                <div className="my-auto grid gap-10 py-10 md:grid-cols-[minmax(0,0.75fr)_minmax(0,1.25fr)] md:items-end">
                    <div>
                        <div
                            className="flex items-start font-semibold tracking-[-0.075em] tabular-nums"
                            aria-label={
                                measured
                                    ? `Health score ${audit.health_score} out of 100`
                                    : 'Health score not measured'
                            }
                        >
                            <span className="text-[5.5rem] leading-[0.82] sm:text-[7rem]">
                                {measured ? audit.health_score : '—'}
                            </span>
                            {measured && (
                                <span className="mt-auto mb-4 ml-2 text-2xl text-emerald-300">
                                    /100
                                </span>
                            )}
                        </div>
                        <p className="mt-5 max-w-60 text-sm leading-6 text-white/55">
                            A weighted reading of everything below, across{' '}
                            {audit.pages_crawled}{' '}
                            {audit.pages_crawled === 1 ? 'page' : 'pages'}.
                        </p>
                    </div>

                    <div>
                        <p className="mb-4 text-[11px] font-medium tracking-[0.14em] text-white/55 uppercase">
                            What makes it up
                        </p>
                        <div className="space-y-4">
                            {groups.map((group) => (
                                <div key={group.key}>
                                    <div className="mb-2 flex items-center justify-between gap-3 text-sm">
                                        <span className="font-medium text-white/75">
                                            {group.label}
                                        </span>
                                        <span className="text-white/55 tabular-nums">
                                            {group.score === null
                                                ? 'Not measured'
                                                : `${group.score}/100`}
                                        </span>
                                    </div>
                                    <div
                                        className="h-1.5 overflow-hidden rounded-full bg-white/10"
                                        role="progressbar"
                                        aria-label={group.label}
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                        aria-valuenow={group.score ?? undefined}
                                        aria-valuetext={
                                            group.score === null
                                                ? 'Not measured'
                                                : `${group.score} out of 100`
                                        }
                                    >
                                        <div
                                            className="h-full rounded-full bg-gradient-to-r from-emerald-400 to-orange-300"
                                            style={{
                                                width: `${Math.max(0, Math.min(100, group.score ?? 0))}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                        {groups.some((group) => group.score === null) && (
                            <p className="mt-4 text-xs leading-relaxed text-white/55">
                                An unmeasured section is left out of the
                                headline rather than counted as zero.
                            </p>
                        )}
                    </div>
                </div>

                <dl className="grid grid-cols-2 gap-y-5 border-t border-white/10 pt-5 sm:grid-cols-4 sm:divide-x sm:divide-white/10">
                    <HeroMetric
                        label="Pages read"
                        value={audit.pages_crawled}
                    />
                    <HeroMetric label="Issues" value={audit.issues_found} />
                    {/* From the server, which counted every row. The table
                        below is capped, so subtracting what it holds would
                        report the pages beyond the cap as clean. */}
                    <HeroMetric
                        label="Clean pages"
                        value={Math.max(
                            0,
                            audit.pages_crawled - audit.pages_with_issues,
                        )}
                    />
                    <HeroMetric
                        label="Page speed"
                        value={
                            audit.speed_score === null
                                ? '—'
                                : `${audit.speed_score}/100`
                        }
                    />
                </dl>
            </div>
        </section>
    );
}

/**
 * What happened to a fix plan that was asked for and has not arrived.
 *
 * Renders nothing in the ordinary case — nobody has asked, so there is nothing
 * to say. The two states worth a card are the ones where the button alone
 * misleads: a run that failed puts the button back looking untouched, and a run
 * that has been going for half an hour is indistinguishable from one that
 * started a moment ago.
 *
 * The slow case is deliberately not called an error. Writing a plan is a model
 * call and a slow provider is not a broken one; what the operator needs is to
 * know it is being waited on rather than to wonder whether they pressed it.
 */
function FixPlanState({
    run,
    writing,
}: {
    run: Props['fix_plan_run'];
    writing: boolean;
}) {
    if (run === null) {
        return null;
    }

    // Measured by the server, which owns the clock: reading Date.now() while
    // rendering is a side effect, and the React compiler is right to refuse it.
    const minutes = run.running_for_minutes ?? 0;

    if (writing) {
        if (minutes < FIX_PLAN_SLOW_AFTER_MINUTES) {
            return null;
        }

        return (
            <Card className={workspacePanelClass}>
                <CardHeader className="flex-row items-center gap-3">
                    <RefreshCw
                        className="size-4 shrink-0 animate-spin text-muted-foreground"
                        aria-hidden="true"
                    />
                    <CardDescription>
                        The fix plan has been writing for {minutes} minutes. It
                        is a single model call, so this usually means the
                        provider is slow to answer — the sweep and its findings
                        above are unaffected.
                    </CardDescription>
                </CardHeader>
            </Card>
        );
    }

    if (run.status !== 'failed') {
        return null;
    }

    return (
        <Card className="rounded-[1.5rem] border-amber-300 bg-amber-50 shadow-none dark:border-amber-900 dark:bg-amber-950/40">
            <CardHeader className="flex-row items-start gap-3">
                <TriangleAlert
                    className="mt-0.5 size-4 shrink-0 text-amber-600"
                    aria-hidden="true"
                />
                <CardDescription className="text-amber-900 dark:text-amber-200">
                    The last fix plan did not finish
                    {run.error ? `: ${run.error}` : '.'} Everything above was
                    still measured — press Get AI fix plan to try again.
                </CardDescription>
            </CardHeader>
        </Card>
    );
}

function HeroMetric({
    label,
    value,
}: {
    label: string;
    value: number | string;
}) {
    return (
        <div className="sm:px-5 sm:first:pl-0 sm:last:pr-0">
            <dt className="text-[11px] tracking-wide text-white/55 uppercase">
                {label}
            </dt>
            <dd className="mt-1.5 text-xl font-semibold tabular-nums">
                {value}
            </dd>
        </div>
    );
}

function PageRow({ page }: { page: Page }) {
    const [open, setOpen] = useState(false);

    return (
        <li className="py-1">
            <Collapsible open={open} onOpenChange={setOpen}>
                <CollapsibleTrigger className="flex w-full items-center gap-3 rounded-xl px-2 py-3 text-left hover:bg-muted/40">
                    {open ? (
                        <ChevronDown
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    ) : (
                        <ChevronRight
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    )}
                    <span className="min-w-0 flex-1 truncate text-sm">
                        {page.path}
                    </span>
                    <span
                        className={`shrink-0 text-sm tabular-nums ${
                            (page.score ?? 0) >= 85
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-foreground'
                        }`}
                    >
                        {page.score === null ? '—' : `${page.score}`}
                        <span className="text-muted-foreground">/100</span>
                    </span>
                    <span className="w-10 shrink-0 text-right text-sm text-muted-foreground tabular-nums">
                        {page.issues_count}
                    </span>
                </CollapsibleTrigger>
                <CollapsibleContent className="space-y-2 px-2 pt-1 pb-3 sm:pl-9">
                    <a
                        href={page.url}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="inline-flex items-center gap-1.5 text-xs text-muted-foreground underline-offset-4 hover:underline"
                    >
                        {page.url}
                        <ExternalLink className="size-3" aria-hidden="true" />
                    </a>
                    {page.issues.map((issue) => (
                        <div
                            key={issue.id}
                            className="rounded-2xl border bg-muted/20 p-4"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge
                                    variant="secondary"
                                    className={SEVERITY_TONE[issue.severity]}
                                >
                                    {issue.severity_label}
                                </Badge>
                                <span className="text-sm font-medium">
                                    {issue.label}
                                </span>
                            </div>
                            <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                {issue.summary}
                            </p>
                            <IssueDetail detail={issue.detail} />
                        </div>
                    ))}
                </CollapsibleContent>
            </Collapsible>
        </li>
    );
}

/**
 * The evidence a check attached to its finding.
 *
 * Rendered generically because the shape is the check's business, not this
 * screen's — a check added next month should show its detail without anybody
 * editing this file. Lists of links get anchors; everything else is a label and
 * a value.
 */
function IssueDetail({ detail }: { detail: Record<string, unknown> }) {
    const entries = Object.entries(detail ?? {}).filter(
        ([, value]) => value !== null && value !== '',
    );

    if (entries.length === 0) {
        return null;
    }

    return (
        <dl className="mt-3 space-y-1.5 text-xs">
            {entries.map(([key, value]) => (
                <div key={key} className="flex flex-wrap gap-x-2">
                    <dt className="text-muted-foreground">
                        {key.replaceAll('_', ' ')}:
                    </dt>
                    <dd className="min-w-0 break-all">{formatValue(value)}</dd>
                </div>
            ))}
        </dl>
    );
}

function formatValue(value: unknown): string {
    if (Array.isArray(value)) {
        return value
            .map((item) =>
                typeof item === 'object' && item !== null
                    ? Object.values(item as Record<string, unknown>).join(' ')
                    : String(item),
            )
            .join(', ');
    }

    if (typeof value === 'object' && value !== null) {
        return Object.entries(value as Record<string, unknown>)
            .map(([key, inner]) => `${key} ${String(inner)}`)
            .join(', ');
    }

    return String(value);
}

function ChecksPanel({ checks }: { checks: Props['checks'] }) {
    const [open, setOpen] = useState(false);

    return (
        <Card className={workspacePanelClass}>
            <Collapsible open={open} onOpenChange={setOpen}>
                <CollapsibleTrigger className="flex w-full items-center justify-between gap-3 p-6 text-left">
                    <div>
                        <div className="text-sm font-medium">
                            Site health checks
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            The {checks.length} things every sweep looks at, and
                            why each one matters.
                        </p>
                    </div>
                    {open ? (
                        <ChevronDown
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    ) : (
                        <ChevronRight
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    )}
                </CollapsibleTrigger>
                <CollapsibleContent className="grid gap-3 px-6 pb-6 sm:grid-cols-2">
                    {checks.map((check) => (
                        <div
                            key={check.key}
                            className="rounded-2xl border bg-muted/20 p-4"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-sm font-medium">
                                    {check.label}
                                </span>
                                <Badge
                                    variant="outline"
                                    className="text-[10px] font-normal"
                                >
                                    {check.group_label}
                                </Badge>
                            </div>
                            <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                                {check.description}
                            </p>
                        </div>
                    ))}
                </CollapsibleContent>
            </Collapsible>
        </Card>
    );
}

function EmptyState({
    running,
    project,
}: {
    running: boolean;
    project: Props['project'];
}) {
    return (
        <Card className={workspacePanelClass}>
            <CardHeader>
                <CardTitle className="text-base">
                    {running
                        ? 'Reading the site now'
                        : 'This site has not been read yet'}
                </CardTitle>
                <CardDescription>
                    {running
                        ? `Crawling ${project.website_url ?? 'the site'}. The first reading takes a few minutes; this page will show it once it finishes.`
                        : project.website_url
                          ? 'The first sweep starts when a project launches, and weekly after that. Press Recheck to read the site now.'
                          : 'This project has no website address, so there is nothing to audit.'}
                </CardDescription>
            </CardHeader>
        </Card>
    );
}

SiteAudit.layout = {
    breadcrumbs: [{ title: 'Site audit', href: index() }],
};
