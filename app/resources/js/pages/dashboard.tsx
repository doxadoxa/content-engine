import { Deferred, Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    ArrowUpRight,
    CalendarDays,
    CheckCircle2,
    ExternalLink,
} from 'lucide-react';
import { useEffect } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { WorkspaceHeader, WorkspacePage } from '@/components/workspace-page';
import { index as approvalsIndex } from '@/routes/approvals';
import { index as calendarIndex } from '@/routes/calendar';
import { show as showContent } from '@/routes/content';
import { show as showOnboarding } from '@/routes/onboarding';
import { edit as editProject } from '@/routes/projects';
import { index as visibilityIndex } from '@/routes/visibility';

type Project = {
    id: string;
    name: string;
    slug: string;
    website_url: string | null;
    onboarding_status: 'draft' | 'analysing' | 'launching' | 'active';
    weekly_target: number;
    is_ymyl: boolean;
};

type ActiveRun = {
    id: string;
    pipeline: string;
    action: string | null;
    status: string;
    subject: string | null;
    started_at: string | null;
    total_steps: number;
    done_steps: number;
    current_step: string | null;
};

type Work = {
    launching: boolean;
    active: ActiveRun[];
    failed: {
        id: string;
        pipeline: string;
        action: string | null;
        subject: string | null;
        step: string | null;
        message: string | null;
    }[];
};

type Stats = {
    published: number;
    planned: number;
    drafts: number;
    awaiting_approval: number;
    targeted_volume: number;
    citations: { checked: number; cited: number };
    search: { impressions: number; clicks: number; days: number };
    engagement: { sessions: number; engaged: number; days: number };
};

type Connected = { search_console: boolean; analytics: boolean };

type Upcoming = {
    id: string;
    title: string;
    state: string;
    scheduled_for: string | null;
    target_query: string | null;
    topic_volume: number | null;
    locales: string[];
};

type Recent = {
    id: string;
    title: string;
    state: string;
    updated_at: string | null;
    public_url: string | null;
    locales: string[];
};

type Health = { healthy: boolean; reason: string | null };

type Visibility = {
    score: number | null;
    monitored_prompts: number;
    mentions: number;
    answered: number;
    last_asked_on: string | null;
    by_locale: { locale: string; score: number | null; answered: number }[];
};

type Props = {
    project: Project | null;
    hasProjects: boolean;
    work?: Work;
    stats?: Stats;
    connected?: Connected;
    upcoming?: Upcoming[];
    recent?: Recent[];
    health?: Health;
    visibility?: Visibility;
};

/**
 * How the pipeline keys read to somebody who did not build them.
 *
 * Two forms each, because these fan out. A month being drafted starts one run
 * per idea, and eighteen rows all reading the same sentence tells an operator
 * less than one row would — so the card groups them and needs a plural to say
 * so in language rather than in a badge.
 */
type Label = { one: string; many: (n: number) => string };

const label = (one: string, many?: (n: number) => string): Label => ({
    one,
    many: many ?? ((n) => `${one} · ${n} running`),
});

const PIPELINE_LABELS: Record<string, Label> = {
    research: label('Researching the market'),
    planning: label('Planning the month'),
    generation: label('Writing an article', (n) => `Writing ${n} articles`),
    content_studio: label("Working on the month's social content"),
    publishing: label('Publishing a post', (n) => `Publishing ${n} posts`),
    refresh: label('Refreshing a page', (n) => `Refreshing ${n} pages`),
    repurpose: label('Cutting an article down for social'),
    site_audit: label('Reading the site'),
    site_audit_fix_plan: label('Writing a fix plan for the site audit'),
    feedback: label('Reading what the published work did'),
    visibility: label('Asking the assistants about the brand'),
};

/**
 * The one pipeline whose key does not say what it is doing.
 *
 * `content_studio` carries six jobs and the card called every one of them
 * "Proposing the social content system" — so a month drafting appeared as
 * eighteen simultaneous proposals of the same thing, which is not a
 * near-enough label, it is the wrong sentence.
 */
const STUDIO_LABELS: Record<string, Label> = {
    proposal: label("Proposing the month's social content"),
    refine: label('Rethinking the month'),
    generate: label('Working out what to write next'),
    generate_idea: label('Writing a post', (n) => `Writing ${n} posts`),
    revise_image: label(
        'Redrawing a picture',
        (n) => `Redrawing ${n} pictures`,
    ),
};

function labelFor(pipeline: string, action: string | null): Label {
    if (pipeline === 'content_studio' && action && STUDIO_LABELS[action]) {
        return STUDIO_LABELS[action];
    }

    return PIPELINE_LABELS[pipeline] ?? label(pipeline);
}

type RunGroup = {
    key: string;
    label: Label;
    subject: string | null;
    count: number;
    doneSteps: number;
    totalSteps: number;
    currentStep: string | null;
};

/**
 * Runs doing the same job, shown once.
 *
 * Grouped by what they are and what they are about, so anything with its own
 * subject — an article being written, a page being refreshed — keeps its own
 * row and only the indistinguishable ones collapse. Progress is summed rather
 * than averaged: "4 of 18" is a thing an operator can watch move, where
 * eighteen bars each reading "0 of 1" are not.
 */
function groupRuns(runs: ActiveRun[]): RunGroup[] {
    const groups = new Map<string, RunGroup>();

    for (const run of runs) {
        const key = `${run.pipeline}:${run.action ?? ''}:${run.subject ?? ''}`;
        const group = groups.get(key);

        if (group) {
            group.count += 1;
            group.doneSteps += run.done_steps;
            group.totalSteps += run.total_steps;
            group.currentStep = group.currentStep ?? run.current_step;
            continue;
        }

        groups.set(key, {
            key,
            label: labelFor(run.pipeline, run.action),
            subject: run.subject,
            count: 1,
            doneSteps: run.done_steps,
            totalSteps: run.total_steps,
            // Only worth naming when one run is being watched. Across a fan-out
            // it is whichever of eighteen happened to sort first, which reads
            // as information and is not any.
            currentStep: run.current_step,
        });
    }

    return [...groups.values()];
}

export default function Dashboard({
    project,
    hasProjects,
    work,
    stats,
    connected,
    upcoming,
    recent,
    health,
    visibility,
}: Props) {
    const { auth } = usePage().props;
    const busy = (work?.active.length ?? 0) > 0 || (work?.launching ?? false);

    // Poll only while work is active, slow down repeated requests, and do not
    // spend server time refreshing a tab nobody can see. A visibility change
    // resets the backoff so returning operators get current state promptly.
    useEffect(() => {
        if (!busy) {
            return;
        }

        let cancelled = false;
        let attempts = 0;
        let timer: number | undefined;

        const schedule = () => {
            const baseDelay = document.hidden
                ? 30_000
                : Math.min(20_000, 5_000 * 2 ** Math.min(attempts, 2));
            const jitter = Math.floor(Math.random() * 1_000);

            timer = window.setTimeout(poll, baseDelay + jitter);
        };

        const poll = () => {
            if (cancelled) {
                return;
            }

            if (document.hidden) {
                schedule();

                return;
            }

            router.reload({
                only: ['work', 'stats', 'upcoming', 'recent'],
                onFinish: () => {
                    if (!cancelled) {
                        attempts += 1;
                        schedule();
                    }
                },
            });
        };

        const onVisibilityChange = () => {
            window.clearTimeout(timer);

            if (document.hidden) {
                schedule();

                return;
            }

            attempts = 0;
            poll();
        };

        document.addEventListener('visibilitychange', onVisibilityChange);
        schedule();

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        };
    }, [busy]);

    if (!project) {
        return <NoProject hasProjects={hasProjects} />;
    }

    return (
        <>
            <Head title={project.name} />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Project overview"
                    context={
                        project.onboarding_status === 'active'
                            ? 'Engine active'
                            : project.onboarding_status
                    }
                    title={project.name}
                    description={
                        project.website_url ??
                        'Content performance and publishing operations'
                    }
                    actions={
                        <>
                            {project.is_ymyl && (
                                <Badge
                                    variant="outline"
                                    className="h-9 rounded-full border-[#d6533c]/25 bg-[#f2d9d0]/60 px-3 text-[#9c3427] dark:text-[#f1a99f]"
                                >
                                    Review required
                                </Badge>
                            )}
                            <Button
                                variant="outline"
                                className="bg-card/75 shadow-sm"
                                asChild
                            >
                                <Link href={calendarIndex()}>
                                    <CalendarDays
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Content calendar
                                </Link>
                            </Button>
                        </>
                    }
                />

                <StoppedNotice health={health} />

                {work && <WorkInProgress work={work} project={project} />}

                {auth.project?.role === 'owner' && (
                    <ConnectGoogle
                        projectId={project.id}
                        connected={connected}
                    />
                )}

                <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(22rem,0.75fr)] [&>*]:min-w-0">
                    <Deferred
                        data="visibility"
                        fallback={() => <LlmVisibilityCard />}
                    >
                        <LlmVisibilityCard visibility={visibility} />
                    </Deferred>

                    <Stats stats={stats} connected={connected} busy={busy} />
                </div>

                <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] [&>*]:min-w-0">
                    <Upcoming items={upcoming} busy={busy} />
                    <Recent items={recent} busy={busy} />
                </div>
            </WorkspacePage>
        </>
    );
}

/**
 * The first hour.
 *
 * A project that was set up ten minutes ago has no articles and no impressions,
 * and a grid of zeroes reads as a broken product rather than a young one. What
 * it does have is work in flight, so that is what the top of the page is until
 * the work stops.
 */
function WorkInProgress({ work, project }: { work: Work; project: Project }) {
    if (work.active.length === 0 && work.failed.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3">
            {work.active.length > 0 && (
                <Card className="gap-5 overflow-hidden rounded-[1.5rem] border-[#d6533c]/20 bg-[#f2d9d0]/45 shadow-none dark:bg-[#d6533c]/10">
                    <CardHeader className="sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <span className="flex size-8 items-center justify-center rounded-full bg-[#d6533c]/10 text-[#d6533c] dark:bg-[#d6533c]/20 dark:text-[#f1a99f]">
                                    <Spinner className="size-4" />
                                </span>
                                {work.launching
                                    ? 'Setting up your project'
                                    : 'Work in progress'}
                            </CardTitle>
                            <CardDescription>
                                {work.launching
                                    ? `Researching what ${project.name} should write about, proposing its social content system, planning a month, and drafting the first few. This takes a while — you can close this page.`
                                    : 'Pipelines currently running for this project.'}
                            </CardDescription>
                        </div>
                        <Badge
                            variant="outline"
                            className="w-fit rounded-full border-[#d6533c]/20 bg-card/60 text-[#9c3427] dark:text-[#f1a99f]"
                        >
                            Live
                        </Badge>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-5">
                        {groupRuns(work.active).map((group) => (
                            <RunProgress key={group.key} group={group} />
                        ))}
                    </CardContent>
                </Card>
            )}

            {work.failed.length > 0 && (
                <Card className="rounded-[1.5rem] border-destructive/30 bg-destructive/[0.035] shadow-none">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <AlertTriangle
                                className="size-4 text-destructive"
                                aria-hidden="true"
                            />
                            Stopped in the last day
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2 text-sm">
                        {work.failed.map((run) => (
                            <div key={run.id} className="flex flex-col">
                                <span className="font-medium">
                                    {labelFor(run.pipeline, run.action).one}
                                    {run.subject && ` · ${run.subject}`}
                                </span>
                                <span className="text-muted-foreground">
                                    {run.step && `at ${run.step}: `}
                                    {run.message ?? 'No message recorded.'}
                                </span>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

function RunProgress({ group }: { group: RunGroup }) {
    const text =
        group.count > 1 ? group.label.many(group.count) : group.label.one;
    const percent =
        group.totalSteps > 0
            ? Math.round((group.doneSteps / group.totalSteps) * 100)
            : 0;

    // A bar is worth drawing only when it can move. These pipelines are not all
    // the same shape: writing an article walks eleven steps and genuinely fills
    // up, while drafting a post is one step, so a fan-out of eighteen of them
    // reads 0 of 18 until the moment each one vanishes from the list. That is a
    // progress bar that is empty for the entire time it is on screen, which
    // looks broken rather than busy. Where there is no step progress to show,
    // the count in the label is the progress — it falls as they land.
    const stepped = group.totalSteps > group.count;

    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="truncate font-medium">
                    {text}
                    {group.subject && (
                        <span className="text-muted-foreground">
                            {' '}
                            · {group.subject}
                        </span>
                    )}
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">
                    {group.totalSteps === 0
                        ? 'starting'
                        : stepped
                          ? `${group.doneSteps} of ${group.totalSteps}`
                          : 'still going'}
                </span>
            </div>
            {stepped && (
                <Progress
                    value={percent}
                    className="h-1.5"
                    indicatorClassName="bg-[#d6533c]"
                    aria-label={`${text} progress`}
                    aria-valuetext={`${group.doneSteps} of ${group.totalSteps} steps complete`}
                />
            )}
            {group.count === 1 && group.currentStep && (
                <p
                    className="text-xs text-muted-foreground"
                    role="status"
                    aria-live="polite"
                >
                    {group.currentStep.replaceAll('_', ' ')}
                </p>
            )}
        </div>
    );
}

/**
 * The numbers this system can actually measure.
 *
 * Backlinks, page speed and sessions are deliberately absent: nothing here
 * collects them, and a card that will read "—" forever teaches an operator to
 * stop reading the row.
 */
function Stats({
    stats,
    connected,
    busy,
}: {
    stats?: Stats;
    connected?: Connected;
    busy: boolean;
}) {
    return (
        <section className="overflow-hidden rounded-[1.5rem] border border-border/90 bg-card/90 shadow-[0_1px_2px_rgba(23,53,47,0.03),0_18px_48px_rgba(23,53,47,0.06)] backdrop-blur-sm">
            <div className="flex items-center justify-between border-b bg-[#f3cf6a]/15 px-5 py-4 sm:px-6 dark:bg-[#f3cf6a]/5">
                <div>
                    <h2 className="font-semibold tracking-tight">
                        Content pulse
                    </h2>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Output and reach at a glance
                    </p>
                </div>
                <span className="rounded-full bg-muted px-2.5 py-1 text-[11px] font-medium text-muted-foreground">
                    Last 28 days
                </span>
            </div>
            <div className="grid grid-cols-2">
                <Deferred
                    data="stats"
                    fallback={() => (
                        <>
                            {[0, 1, 2, 3, 4].map((index) => (
                                <StatSkeleton
                                    key={index}
                                    featured={index === 0}
                                />
                            ))}
                        </>
                    )}
                >
                    {stats && (
                        <>
                            <Stat
                                index="01"
                                label="Published"
                                value={stats.published}
                                hint={
                                    busy && stats.published === 0
                                        ? 'First drafts are being written'
                                        : `${stats.drafts} in draft · ${stats.awaiting_approval} waiting for you`
                                }
                                href={
                                    stats.awaiting_approval > 0
                                        ? approvalsIndex()
                                        : undefined
                                }
                                featured
                            />
                            <Stat
                                index="02"
                                label="Targeted search volume"
                                value={stats.targeted_volume.toLocaleString()}
                                hint={`Across ${stats.planned} planned topics`}
                            />
                            <Stat
                                index="03"
                                label="Cited by assistants"
                                value={
                                    stats.citations.checked === 0
                                        ? '—'
                                        : `${stats.citations.cited} of ${stats.citations.checked}`
                                }
                                hint={
                                    stats.citations.checked === 0
                                        ? 'Checked once articles are live'
                                        : 'Articles an assistant quoted back'
                                }
                            />
                            <Stat
                                index="04"
                                label="Search impressions"
                                value={
                                    connected?.search_console === false
                                        ? '—'
                                        : stats.search.impressions.toLocaleString()
                                }
                                hint={
                                    connected?.search_console === false
                                        ? 'Connect Search Console to see this'
                                        : `${stats.search.clicks.toLocaleString()} clicks · last ${stats.search.days} days`
                                }
                            />
                            <Stat
                                index="05"
                                label="Engaged visits"
                                value={
                                    connected?.analytics === false
                                        ? '—'
                                        : engagementRate(stats)
                                }
                                hint={
                                    connected?.analytics === false
                                        ? 'Connect Analytics to see this'
                                        : `${stats.engagement.sessions.toLocaleString()} visits · last ${stats.engagement.days} days`
                                }
                            />
                        </>
                    )}
                </Deferred>
            </div>
        </section>
    );
}

/**
 * The share of visits that held attention.
 *
 * A rate rather than a count, because the count only says how much traffic
 * arrived — which the card beside this one already covers.
 */
function engagementRate(stats: Stats): string {
    if (stats.engagement.sessions === 0) {
        return '—';
    }

    return `${Math.round((stats.engagement.engaged / stats.engagement.sessions) * 100)}%`;
}

/**
 * Shown only while something is missing.
 *
 * The two cards above read "—" without a connection, and an operator cannot
 * tell that apart from a project nobody has visited yet. This says which.
 */
function ConnectGoogle({
    projectId,
    connected,
}: {
    projectId: string;
    connected?: Connected;
}) {
    if (!connected || (connected.search_console && connected.analytics)) {
        return null;
    }

    const missing =
        !connected.search_console && !connected.analytics
            ? 'Search Console and Analytics'
            : !connected.search_console
              ? 'Search Console'
              : 'Analytics';

    return (
        <Card className="gap-0 rounded-[1.5rem] border-dashed border-[#3155a5]/35 bg-[#d7e0f6]/35 py-0 shadow-none dark:bg-[#3155a5]/10">
            <CardHeader className="flex-col items-start gap-4 space-y-0 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0 py-5">
                    <CardTitle className="text-base">
                        Connect {missing}
                    </CardTitle>
                    <CardDescription>
                        Until you do, the engine is writing without seeing what
                        any of it did.
                    </CardDescription>
                </div>
                <Button
                    variant="outline"
                    className="mb-5 rounded-full sm:my-5"
                    asChild
                >
                    <Link href={editProject(projectId)}>
                        Connect
                        <ArrowRight className="size-4" aria-hidden="true" />
                    </Link>
                </Button>
            </CardHeader>
        </Card>
    );
}

function Stat({
    index,
    label,
    value,
    hint,
    href,
    featured = false,
}: {
    index: string;
    label: string;
    value: number | string;
    hint: string;
    href?: { url: string; method: 'get' };
    featured?: boolean;
}) {
    const className = `group relative flex h-full min-h-32 flex-col justify-between border-b p-5 transition-colors hover:bg-[#f3cf6a]/10 focus-visible:z-10 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring sm:p-6 ${
        featured
            ? 'col-span-2 min-h-40 bg-[#f2d9d0]/32 dark:bg-[#d6533c]/7'
            : 'even:border-r'
    }`;

    const body = (
        <>
            <div className="flex items-center justify-between gap-3 text-xs font-medium text-muted-foreground">
                <span className="flex items-center gap-2.5">
                    <span className="font-serif text-sm text-[#d6533c] italic">
                        {index}
                    </span>
                    <span className="h-px w-5 bg-border" />
                    {label}
                </span>
                {href && (
                    <ArrowUpRight
                        className="size-4 opacity-40 transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-100"
                        aria-hidden="true"
                    />
                )}
            </div>
            <div className="mt-5">
                <div
                    className={`font-serif font-normal tracking-[-0.045em] tabular-nums ${featured ? 'text-6xl' : 'text-3xl'}`}
                >
                    {value}
                </div>
                <p className="mt-1.5 text-xs leading-5 text-muted-foreground">
                    {hint}
                </p>
            </div>
        </>
    );

    return href ? (
        <Link href={href} className={className}>
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}

function StatSkeleton({ featured = false }: { featured?: boolean }) {
    return (
        <div
            className={`flex min-h-32 flex-col justify-between border-b p-5 sm:p-6 ${featured ? 'col-span-2 min-h-40' : 'even:border-r'}`}
        >
            <Skeleton className="h-7 w-28 rounded-lg" />
            <div className="mt-5 space-y-2">
                <Skeleton className={featured ? 'h-11 w-20' : 'h-7 w-16'} />
                <Skeleton className="h-3 w-32 max-w-full" />
            </div>
        </div>
    );
}

function Upcoming({ items, busy }: { items?: Upcoming[]; busy: boolean }) {
    return (
        <Card className="gap-0 overflow-hidden rounded-[1.5rem] border-border/90 bg-card/90 py-0 shadow-[0_1px_2px_rgba(23,53,47,0.03),0_18px_48px_rgba(23,53,47,0.05)] backdrop-blur-sm">
            <CardHeader className="flex-row items-start justify-between gap-4 border-b bg-[#f3cf6a]/10 px-5 py-5 sm:px-6 dark:bg-[#f3cf6a]/5">
                <div>
                    <CardTitle className="text-base tracking-tight">
                        Coming up
                    </CardTitle>
                    <CardDescription className="mt-1">
                        Next in the publishing queue
                    </CardDescription>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    className="-mr-2 rounded-full text-xs text-muted-foreground"
                    asChild
                >
                    <Link href={calendarIndex()}>
                        View plan
                        <ArrowUpRight className="size-3.5" />
                    </Link>
                </Button>
            </CardHeader>
            <CardContent className="px-0">
                <Deferred data="upcoming" fallback={() => <ListSkeleton />}>
                    {items && items.length === 0 ? (
                        <Pending
                            busy={busy}
                            waiting="The planner is still choosing topics."
                            empty="Nothing scheduled. The planner fills the next month on its weekly run."
                        />
                    ) : (
                        <ul className="flex flex-col divide-y">
                            {items?.map((item) => (
                                <li key={item.id}>
                                    <Link
                                        href={showContent(item.id)}
                                        className="group flex min-w-0 items-center justify-between gap-4 px-5 py-4 transition-colors hover:bg-muted/45 sm:px-6"
                                    >
                                        <span className="flex min-w-0 items-start gap-3">
                                            <span className="mt-1 flex size-7 shrink-0 items-center justify-center rounded-lg bg-[#f2d9d0] font-serif text-[11px] font-semibold text-[#9c3427] italic dark:bg-[#d6533c]/15 dark:text-[#f1a99f]">
                                                {item.scheduled_for
                                                    ? new Date(
                                                          `${item.scheduled_for}T00:00:00`,
                                                      ).toLocaleDateString(
                                                          undefined,
                                                          {
                                                              day: '2-digit',
                                                          },
                                                      )
                                                    : '—'}
                                            </span>
                                            <span className="min-w-0 text-sm leading-5 break-words">
                                                <span className="font-medium group-hover:underline">
                                                    {item.title}
                                                </span>
                                                {item.target_query && (
                                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                                        {item.target_query}
                                                        {item.topic_volume
                                                            ? ` · ${item.topic_volume.toLocaleString()}/mo`
                                                            : ''}
                                                    </span>
                                                )}
                                                {item.locales.length > 1 && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {item.locales.length}{' '}
                                                        languages
                                                    </span>
                                                )}
                                            </span>
                                        </span>
                                        <span className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                                            {item.scheduled_for ??
                                                'Unscheduled'}
                                            <ArrowRight className="size-3.5 opacity-0 transition-all group-hover:translate-x-0.5 group-hover:opacity-100" />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Deferred>
            </CardContent>
        </Card>
    );
}

function Recent({ items, busy }: { items?: Recent[]; busy: boolean }) {
    return (
        <Card className="min-w-0 gap-0 overflow-hidden rounded-[1.5rem] border-border/90 bg-card/90 py-0 shadow-[0_1px_2px_rgba(23,53,47,0.03),0_18px_48px_rgba(23,53,47,0.05)] backdrop-blur-sm">
            <CardHeader className="border-b bg-[#3155a5]/6 px-5 py-5 sm:px-6 dark:bg-[#3155a5]/10">
                <CardTitle className="text-base tracking-tight">
                    Latest work
                </CardTitle>
                <CardDescription className="mt-1">
                    Recently touched drafts and articles
                </CardDescription>
            </CardHeader>
            <CardContent className="px-0">
                <Deferred data="recent" fallback={() => <ListSkeleton />}>
                    {items && items.length === 0 ? (
                        <Pending
                            busy={busy}
                            waiting="The first drafts are being written now."
                            empty="Nothing written yet."
                        />
                    ) : (
                        <ul className="flex flex-col divide-y">
                            {items?.map((item) => (
                                <li
                                    key={item.id}
                                    className="group flex min-w-0 items-center justify-between gap-3 px-5 py-4 transition-colors hover:bg-muted/45 sm:px-6"
                                >
                                    <Link
                                        href={showContent(item.id)}
                                        className="min-w-0 truncate text-sm font-medium group-hover:underline"
                                    >
                                        {item.title}
                                        {item.locales.length > 1 && (
                                            <span className="ml-1 text-xs text-muted-foreground">
                                                · {item.locales.length} langs
                                            </span>
                                        )}
                                    </Link>
                                    <span className="flex shrink-0 items-center gap-2">
                                        <Badge
                                            variant={
                                                item.state === 'published'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                            className="rounded-full px-2.5 font-medium capitalize"
                                        >
                                            {item.state}
                                        </Badge>
                                        {item.public_url && (
                                            <a
                                                href={item.public_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                aria-label="Open the published article"
                                            >
                                                <ExternalLink
                                                    className="size-3.5 text-muted-foreground"
                                                    aria-hidden="true"
                                                />
                                            </a>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Deferred>
            </CardContent>
        </Card>
    );
}

/**
 * Empty because it has not happened yet, or empty because there is nothing.
 *
 * The two look identical and mean opposite things — one is worth waiting for
 * and the other is worth acting on — so the running state decides the wording.
 */
function Pending({
    busy,
    waiting,
    empty,
}: {
    busy: boolean;
    waiting: string;
    empty: string;
}) {
    return (
        <p className="flex items-center gap-2 px-5 py-5 text-sm text-muted-foreground sm:px-6">
            {busy ? (
                <>
                    <Spinner className="size-3.5" />
                    {waiting}
                </>
            ) : (
                <>
                    <CheckCircle2 className="size-3.5" aria-hidden="true" />
                    {empty}
                </>
            )}
        </p>
    );
}

function ListSkeleton() {
    return (
        <div className="flex flex-col gap-3 px-5 py-5 sm:px-6">
            {[0, 1, 2].map((index) => (
                <Skeleton key={index} className="h-5 w-full" />
            ))}
        </div>
    );
}

function StoppedNotice({ health }: { health?: Health }) {
    return (
        <Deferred data="health" fallback={() => null}>
            {health && !health.healthy && (
                <Card className="rounded-[1.5rem] border-amber-500/35 bg-amber-50/60 shadow-none dark:bg-amber-950/20">
                    <CardHeader className="flex-row items-start gap-3 space-y-0">
                        <AlertTriangle
                            className="mt-0.5 size-5 shrink-0 text-amber-600"
                            aria-hidden="true"
                        />
                        <div>
                            <CardTitle className="text-base">
                                The engine is not running work right now
                            </CardTitle>
                            <CardDescription>
                                {health.reason} Scheduled pipelines will not
                                produce drafts until this is fixed.
                            </CardDescription>
                        </div>
                    </CardHeader>
                </Card>
            )}
        </Deferred>
    );
}

function NoProject({ hasProjects }: { hasProjects: boolean }) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex min-h-[65vh] flex-col justify-center p-6">
                <Card className="mx-auto max-w-lg overflow-hidden rounded-[1.75rem] border-border/80 bg-card/90 px-4 py-10 text-center shadow-[0_24px_80px_rgba(23,53,47,0.1)]">
                    <CardHeader className="items-center text-center">
                        <span className="mb-3 font-serif text-5xl leading-none text-[#d6533c] italic">
                            {hasProjects ? '02' : '01'}
                        </span>
                        <span className="mb-3 h-px w-12 bg-border" />
                        <CardTitle className="text-xl tracking-[-0.03em]">
                            {hasProjects
                                ? 'Pick a project'
                                : 'Set up your first project'}
                        </CardTitle>
                        <CardDescription>
                            {hasProjects
                                ? 'Choose one from the switcher at the top of the sidebar.'
                                : 'Give us the website. We read it, work out what it should be writing about, and start.'}
                        </CardDescription>
                        {!hasProjects && (
                            <Button asChild className="mt-2">
                                <Link href={showOnboarding()}>
                                    Start
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </Button>
                        )}
                    </CardHeader>
                </Card>
            </div>
        </>
    );
}

/**
 * Where the brand stands in AI answers.
 *
 * The per-language line under the headline is not decoration. This project read
 * 0% overall while a customer arrived through ChatGPT answering in Russian —
 * one number across every language is exactly how that gets missed, so the
 * breakdown sits beside the headline rather than a click away.
 */
function LlmVisibilityCard({ visibility }: { visibility?: Visibility }) {
    // Undefined is still loading; a null score is loaded-but-never-asked. Both
    // differ from 0%, which is a real and bad measurement.
    const measured = visibility !== undefined && visibility.score !== null;

    return (
        <section className="relative isolate min-h-[34rem] overflow-hidden rounded-[1.75rem] border border-[#36544e] bg-[#17352f] text-[#fffaf0] shadow-[0_24px_80px_rgba(23,53,47,0.2)] xl:min-h-full">
            <div
                className="pointer-events-none absolute -top-32 -right-28 -z-10 size-80 rounded-full border-[3.25rem] border-[#d6533c]/55"
                aria-hidden="true"
            />
            <div
                className="pointer-events-none absolute -bottom-28 left-[36%] -z-10 h-48 w-72 rotate-[-12deg] bg-[#3155a5]/35"
                aria-hidden="true"
            />

            <div className="flex h-full flex-col p-6 sm:p-8">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="text-xs font-medium tracking-[0.14em] text-[#f3cf6a] uppercase">
                            AI visibility
                        </div>
                        <p className="mt-2 text-sm text-white/50">
                            {visibility?.last_asked_on
                                ? `Last measured ${visibility.last_asked_on}`
                                : 'The first prompt sweep has not run yet'}
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="rounded-full border border-white/10 bg-white/5 text-white hover:bg-white/10 hover:text-white"
                        asChild
                    >
                        <Link
                            href={visibilityIndex().url}
                            aria-label="Open prompt analysis"
                        >
                            <ArrowUpRight className="size-4" />
                        </Link>
                    </Button>
                </div>

                <div className="my-auto grid gap-10 py-10 md:grid-cols-[minmax(0,0.75fr)_minmax(0,1.25fr)] md:items-end">
                    <div>
                        <div className="flex items-start font-serif font-normal tracking-[-0.075em] tabular-nums">
                            <span className="text-[5.5rem] leading-[0.82] sm:text-[7rem]">
                                {measured ? visibility?.score : '—'}
                            </span>
                            {measured && (
                                <span className="ml-2 text-2xl text-[#f3cf6a]">
                                    %
                                </span>
                            )}
                        </div>
                        <p className="mt-5 max-w-52 text-sm leading-6 text-white/55">
                            Share of answered prompts where your brand appeared.
                        </p>
                    </div>

                    <div>
                        <p className="mb-4 text-[11px] font-medium tracking-[0.14em] text-white/40 uppercase">
                            Visibility by locale
                        </p>
                        {(visibility?.by_locale ?? []).length === 0 ? (
                            <div className="rounded-2xl border border-white/8 bg-white/[0.035] p-5 text-sm text-white/45">
                                No prompt results yet. Locale performance will
                                appear here after the first sweep.
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {visibility?.by_locale.map((row, index) => (
                                    <div key={row.locale}>
                                        <div className="mb-2 flex items-center justify-between gap-3 text-sm">
                                            <span className="font-medium text-white/75">
                                                {row.locale}
                                            </span>
                                            <span className="text-white/55 tabular-nums">
                                                {row.score === null
                                                    ? 'Not measured'
                                                    : `${row.score}%`}
                                            </span>
                                        </div>
                                        <div className="h-1.5 overflow-hidden rounded-full bg-white/10">
                                            <div
                                                className={`h-full rounded-full ${index % 2 === 0 ? 'bg-[#d6533c]' : 'bg-[#3155a5]'}`}
                                                style={{
                                                    width: `${Math.max(0, Math.min(100, row.score ?? 0))}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-2 divide-x divide-white/10 border-t border-white/10 pt-5">
                    <div className="pr-5">
                        <p className="text-[11px] tracking-wide text-white/40 uppercase">
                            Monitored prompts
                        </p>
                        <p className="mt-1.5 font-serif text-2xl tabular-nums">
                            {visibility?.monitored_prompts ?? '—'}
                        </p>
                    </div>
                    <div className="pl-5">
                        <p className="text-[11px] tracking-wide text-white/40 uppercase">
                            Brand mentions
                        </p>
                        <p className="mt-1.5 font-serif text-2xl tabular-nums">
                            {visibility === undefined
                                ? '—'
                                : `${visibility.mentions} / ${visibility.answered}`}
                        </p>
                    </div>
                </div>
            </div>
        </section>
    );
}
