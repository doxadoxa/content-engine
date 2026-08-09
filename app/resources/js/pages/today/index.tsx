import { Deferred, Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    CircleSlash,
    ExternalLink,
    MessagesSquare,
    Minus,
    PenLine,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';
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
import { index as engageIndex } from '@/routes/engage';
import { index as todayIndex } from '@/routes/today';

type Conversation = {
    id: string;
    author: string;
    handle: string | null;
    excerpt: string;
    permalink: string | null;
    received_at: string;
    /** The server's clock, and the same number the reply queue puts on its rows. */
    waited_seconds: number;
    state: string;
    state_label: string;
    has_draft: boolean;
};

type Bucket = { key: string; label: string; count: number };

type ReplyDraft = {
    id: string;
    author: string;
    handle: string | null;
    draft_excerpt: string;
    waited_seconds: number;
};

type PostOfTheDay = {
    id: string;
    title: string;
    state: string;
    state_label: string;
    band: string | null;
    band_label: string | null;
    slot_at: string | null;
    expires_at: string | null;
    expired: boolean;
    published: boolean;
    empty: boolean;
    url: string;
};

type Metric = {
    key: string;
    label: string;
    unit: 'per_day' | 'ratio';
    note: string;
    window_days: number;
    /** False means nobody measured it, which is never the same as zero. */
    measured: boolean;
    measured_days: number;
    current: number | null;
    previous: number | null;
    change: number | null;
    direction: 'up' | 'down' | 'flat' | null;
    points: { day: string; value: number | null }[];
};

type NotDoneEntry = {
    code: string;
    label: string;
    detail: string;
    at: string | null;
};

type Props = {
    project: { name: string; timezone: string; today: string } | null;
    happened?: {
        total: number;
        arrived_today: number;
        answered_today: number;
        longest_wait_seconds: number | null;
        buckets: Bucket[];
        conversations: Conversation[];
    };
    awaiting?: {
        reply_drafts: { count: number; rows: ReplyDraft[] };
        post_of_the_day: PostOfTheDay | null;
    };
    not_done?: {
        week_start: string;
        entries: NotDoneEntry[];
        throttle: {
            reply_rate: number | null;
            ceiling: number;
            selection_floor: number;
            detail: string;
        } | null;
        alert: string | null;
        planned: number | null;
        ceiling: number | null;
        floor: number | null;
        nothing_to_report: boolean;
        summary: string;
    };
    changed?: Metric[];
};

/**
 * §7 — the project's day in one screen and five minutes.
 *
 * Mobile by default, which the spec asks for by name: "подтверждение ответа за
 * минуты с телефона, а не за час с ноутбука". Every section is a single column
 * that widens rather than a grid that reflows, the wait is the largest thing on
 * a conversation row, and every action is a link into the screen that already
 * owns it — this page decides nothing and sends nothing.
 *
 * The four sections are §7's four lines in §7's order, and the last one always
 * renders. See `NotDone` below.
 */
export default function Today({
    project,
    happened,
    awaiting,
    not_done,
    changed,
}: Props) {
    if (!project) {
        return (
            <>
                <Head title="Today" />
                <WorkspacePage width="reading">
                    <WorkspaceHeader
                        eyebrow="The operator's day"
                        title="Today"
                        description="Choose a project to see its day."
                    />
                </WorkspacePage>
            </>
        );
    }

    return (
        <>
            <Head title="Today" />

            <WorkspacePage width="reading">
                <WorkspaceHeader
                    eyebrow="The operator's day"
                    context={new Date(
                        `${project.today}T12:00:00`,
                    ).toLocaleDateString(undefined, {
                        weekday: 'long',
                        day: 'numeric',
                        month: 'long',
                    })}
                    title="Today"
                    description={`Everything ${project.name} needs from you, and everything the engine decided on its own.`}
                />

                {happened && <Happened happened={happened} />}
                {awaiting && <Awaiting awaiting={awaiting} />}

                <Deferred data="changed" fallback={() => <ChangedSkeleton />}>
                    <Changed metrics={changed ?? []} />
                </Deferred>

                {not_done && <NotDone notDone={not_done} />}
            </WorkspacePage>
        </>
    );
}

/** Section one: what happened, longest wait first. */
function Happened({ happened }: { happened: NonNullable<Props['happened']> }) {
    return (
        <Section
            icon={<MessagesSquare className="size-4" aria-hidden="true" />}
            tone="bg-violet-500/10 text-violet-600 dark:text-violet-300"
            title="What happened"
            description="New replies and mentions, most urgent first — urgency is how long somebody has been waiting."
        >
            <div className="flex flex-wrap items-baseline gap-x-6 gap-y-2 px-5 pt-1 pb-4 sm:px-6">
                <Figure
                    value={String(happened.total)}
                    label="waiting for a reply"
                />
                <Figure
                    value={
                        happened.longest_wait_seconds === null
                            ? '—'
                            : waited(happened.longest_wait_seconds)
                    }
                    label="longest wait"
                    tone={tone(happened.longest_wait_seconds)}
                />
                <Figure
                    value={String(happened.arrived_today)}
                    label="arrived today"
                />
                <Figure
                    value={String(happened.answered_today)}
                    label="answered today"
                />
            </div>

            <div className="flex flex-wrap gap-2 px-5 pb-4 sm:px-6">
                {happened.buckets.map((bucket) => (
                    <Badge
                        key={bucket.key}
                        variant={bucket.count === 0 ? 'outline' : 'secondary'}
                        className="rounded-full"
                    >
                        {bucket.count} {bucket.label.toLowerCase()}
                    </Badge>
                ))}
            </div>

            {happened.conversations.length === 0 ? (
                <p className="border-t px-5 py-6 text-sm text-muted-foreground sm:px-6">
                    Nothing is waiting. Every conversation that arrived has been
                    answered or deliberately left alone.
                </p>
            ) : (
                <ul className="flex flex-col divide-y border-t">
                    {happened.conversations.map((conversation) => (
                        <li
                            key={conversation.id}
                            className={`flex items-start gap-4 px-5 py-4 sm:px-6 ${accent(conversation.waited_seconds)}`}
                        >
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {conversation.handle ?? conversation.author}
                                    {conversation.has_draft && (
                                        <Badge
                                            variant="secondary"
                                            className="ml-2 rounded-full text-[0.65rem]"
                                        >
                                            draft ready
                                        </Badge>
                                    )}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {conversation.excerpt}
                                </p>
                                {conversation.permalink && (
                                    <a
                                        href={conversation.permalink}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                        className="mt-2 inline-flex items-center gap-1 text-xs text-muted-foreground underline underline-offset-4"
                                    >
                                        <ExternalLink
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                        Open the thread
                                    </a>
                                )}
                            </div>
                            <p
                                className={`shrink-0 text-xl leading-none font-semibold tabular-nums ${tone(conversation.waited_seconds)}`}
                            >
                                {waited(conversation.waited_seconds)}
                            </p>
                        </li>
                    ))}
                </ul>
            )}

            <div className="border-t px-5 py-4 sm:px-6">
                <Button asChild variant="outline" className="w-full sm:w-auto">
                    <Link href={engageIndex()} prefetch>
                        Open the reply queue
                        <ArrowRight className="size-4" aria-hidden="true" />
                    </Link>
                </Button>
            </div>
        </Section>
    );
}

/**
 * Section two: what is waiting for a human.
 *
 * The drafts are a link and not a form. The reply queue is already one tap and
 * §4.2 is measured on it; a second send button here would be a second place for
 * the guard, the acknowledgements and the routing to drift out of step.
 */
function Awaiting({ awaiting }: { awaiting: NonNullable<Props['awaiting']> }) {
    const { reply_drafts, post_of_the_day } = awaiting;

    return (
        <Section
            icon={<PenLine className="size-4" aria-hidden="true" />}
            tone="bg-amber-500/10 text-amber-600 dark:text-amber-300"
            title="What awaits you"
            description="Written by the engine, sent by you. Nothing on this channel goes out without a person (§4.2)."
        >
            <div className="border-t px-5 py-4 sm:px-6">
                <p className="text-sm font-medium">
                    {reply_drafts.count === 0
                        ? 'No reply drafts are waiting.'
                        : `${reply_drafts.count} reply ${reply_drafts.count === 1 ? 'draft' : 'drafts'} ready to send`}
                </p>

                {reply_drafts.rows.length > 0 && (
                    <ul className="mt-3 flex flex-col gap-3">
                        {reply_drafts.rows.map((row) => (
                            <li key={row.id} className="text-sm">
                                <span className="font-medium">
                                    {row.handle ?? row.author}
                                </span>
                                <span
                                    className={`ml-2 text-xs tabular-nums ${tone(row.waited_seconds)}`}
                                >
                                    {waited(row.waited_seconds)} waiting
                                </span>
                                <p className="mt-0.5 text-muted-foreground">
                                    {row.draft_excerpt}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}

                {reply_drafts.count > 0 && (
                    <Button
                        asChild
                        className="mt-4 w-full sm:w-auto"
                        variant="outline"
                    >
                        <Link href={engageIndex()} prefetch>
                            Approve them in the queue
                            <ArrowRight className="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                )}
            </div>

            <div className="border-t px-5 py-4 sm:px-6">
                <p className="text-sm font-medium">The post of the day</p>

                {post_of_the_day === null ? (
                    <p className="mt-1 text-sm text-muted-foreground">
                        No slot stands on today's calendar. The week's slots are
                        placed by the planner and only where you are on duty for
                        the hour after (§4.3).
                    </p>
                ) : (
                    <div className="mt-2">
                        <p className="font-medium">{post_of_the_day.title}</p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <Badge variant="outline" className="rounded-full">
                                {post_of_the_day.state_label}
                            </Badge>
                            {post_of_the_day.band_label && (
                                <Badge
                                    variant="secondary"
                                    className="rounded-full"
                                >
                                    {post_of_the_day.band_label}
                                </Badge>
                            )}
                            {post_of_the_day.slot_at && (
                                <span className="text-xs text-muted-foreground">
                                    {new Date(
                                        post_of_the_day.slot_at,
                                    ).toLocaleTimeString(undefined, {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                </span>
                            )}
                        </div>

                        {post_of_the_day.empty && (
                            <p className="mt-2 text-sm text-muted-foreground">
                                The slot was placed and came to nothing. The
                                reason is below, under what the engine did not
                                do — an empty slot is a valid result, not a
                                fault (§4.3).
                            </p>
                        )}

                        <Button
                            asChild
                            variant="outline"
                            className="mt-4 w-full sm:w-auto"
                        >
                            <Link href={post_of_the_day.url} prefetch>
                                Open the post
                                <ArrowRight
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </Link>
                        </Button>
                    </div>
                )}
            </div>
        </Section>
    );
}

/** Section three: what changed — three shapes, never three readings. */
function Changed({ metrics }: { metrics: Metric[] }) {
    return (
        <Section
            icon={<CalendarClock className="size-4" aria-hidden="true" />}
            tone="bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"
            title="What changed"
            description="A fortnight against the fortnight before it. Presence is measured by what happens to the project, not by likes (§6)."
        >
            <div className="grid gap-px border-t bg-border sm:grid-cols-3">
                {metrics.map((metric) => (
                    <div key={metric.key} className="bg-card px-5 py-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            {metric.label}
                        </p>

                        {metric.measured ? (
                            <>
                                <p className="mt-2 text-2xl font-semibold tabular-nums">
                                    {format(metric)}
                                </p>
                                <Direction metric={metric} />
                            </>
                        ) : (
                            // Not a zero, and deliberately not styled like a
                            // number. §6 makes every vendor column nullable so
                            // that "the connection is gone" and "the audience
                            // left" cannot look the same.
                            <p className="mt-2 text-sm text-muted-foreground italic">
                                Not measured
                            </p>
                        )}

                        <Sparkline points={metric.points} />

                        <p className="mt-2 text-xs text-muted-foreground">
                            {metric.measured
                                ? `${metric.measured_days} of ${metric.window_days} days measured`
                                : `No day in the last ${metric.window_days} carried this figure.`}
                        </p>
                    </div>
                ))}
            </div>
        </Section>
    );
}

/**
 * Section four: what the engine did not do, and why.
 *
 * This renders whatever happens, and that is the entire point of it. §7: "Молчащий
 * автомат неотличим от сломанного, и единственная вещь, которая делает
 * «сегодня не публикуем» приемлемой, — объяснение рядом." A week in which
 * nothing was refused gets a sentence saying nothing was refused; it never gets
 * an empty panel, because an empty panel is the silence the paragraph forbids.
 */
function NotDone({ notDone }: { notDone: NonNullable<Props['not_done']> }) {
    return (
        <Section
            icon={<CircleSlash className="size-4" aria-hidden="true" />}
            tone="bg-rose-500/10 text-rose-600 dark:text-rose-300"
            title="What the engine did not do, and why"
            description={`The week of ${notDone.week_start}. Refusing to publish is a valid result — an unexplained refusal is not (§7).`}
        >
            <div className="border-t px-5 py-4 sm:px-6">
                <p className="text-sm">{notDone.summary}</p>

                {notDone.planned !== null && (
                    <p className="mt-2 text-xs text-muted-foreground">
                        {notDone.planned} planned this week against a ceiling of{' '}
                        {notDone.ceiling} and a floor of {notDone.floor}.
                    </p>
                )}
            </div>

            {notDone.entries.length > 0 && (
                <ul className="flex flex-col divide-y border-t">
                    {notDone.entries.map((entry, index) => (
                        <li
                            key={`${entry.code}-${index}`}
                            className="px-5 py-4 sm:px-6"
                        >
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                {entry.label}
                            </p>
                            <p className="mt-1 text-sm">{entry.detail}</p>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

/** The frame every section shares, so the four read as one screen. */
function Section({
    icon,
    tone: iconTone,
    title,
    description,
    children,
}: {
    icon: React.ReactNode;
    tone: string;
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <Card className={`${workspacePanelClass} gap-0 overflow-hidden p-0`}>
            <CardHeader className="px-5 py-5 sm:px-6">
                <div className="flex items-start gap-3">
                    <span
                        className={`flex size-9 shrink-0 items-center justify-center rounded-xl ${iconTone}`}
                    >
                        {icon}
                    </span>
                    <div className="min-w-0">
                        <CardTitle className="text-base">{title}</CardTitle>
                        <CardDescription className="mt-1">
                            {description}
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            {children}
        </Card>
    );
}

function ChangedSkeleton() {
    return (
        <Card className={workspacePanelClass}>
            <CardContent className="grid gap-4 sm:grid-cols-3">
                {[0, 1, 2].map((index) => (
                    <div
                        key={index}
                        className="h-24 animate-pulse rounded-xl bg-muted/60"
                    />
                ))}
            </CardContent>
        </Card>
    );
}

function Figure({
    value,
    label,
    tone: valueTone,
}: {
    value: string;
    label: string;
    tone?: string;
}) {
    return (
        <div>
            <p
                className={`text-2xl leading-none font-semibold tabular-nums ${valueTone ?? ''}`}
            >
                {value}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">{label}</p>
        </div>
    );
}

function Direction({ metric }: { metric: Metric }) {
    if (metric.direction === null) {
        return (
            <p className="mt-1 text-xs text-muted-foreground">
                Nothing to compare it with yet.
            </p>
        );
    }

    const Icon =
        metric.direction === 'up'
            ? TrendingUp
            : metric.direction === 'down'
              ? TrendingDown
              : Minus;

    return (
        <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
            <Icon className="size-3" aria-hidden="true" />
            {metric.change === null
                ? metric.direction
                : `${metric.change > 0 ? '+' : ''}${(metric.change * 100).toFixed(0)}% on the fortnight before`}
        </p>
    );
}

/**
 * The shape, with the holes left in.
 *
 * A day nobody measured draws no bar at all rather than a bar of height zero.
 * The whole reason `project_states` keeps a row a day is that the trend carries
 * the meaning, and a trend with unmeasured days flattened into it is a
 * different trend.
 */
function Sparkline({ points }: { points: Metric['points'] }) {
    const values = points
        .map((point) => point.value)
        .filter((value): value is number => value !== null);
    const peak = Math.max(1, ...values);

    return (
        <div className="mt-3 flex h-8 items-end gap-0.5" aria-hidden="true">
            {points.map((point) => (
                <div key={point.day} className="flex-1">
                    {point.value === null ? (
                        <div className="h-px w-full bg-border" />
                    ) : (
                        <div
                            className="w-full rounded-t-sm bg-emerald-500/60"
                            style={{
                                height: `${Math.max(6, (point.value / peak) * 32)}px`,
                            }}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

function format(metric: Metric): string {
    if (metric.current === null) {
        return '—';
    }

    return metric.unit === 'ratio'
        ? `${(metric.current * 100).toFixed(2)}%`
        : `${Math.round(metric.current).toLocaleString()}/day`;
}

/**
 * The wait in the words a person would use.
 *
 * The thresholds match `waited()` in `engage/index.tsx` and
 * `InteractionController::waited()` to the second, because a wait described two
 * ways on two screens is the one number on either that must not be wrong.
 */
function waited(seconds: number): string {
    if (seconds < 60) {
        return `${Math.max(0, seconds)}s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h`;
    }

    return `${Math.floor(seconds / 86400)}d`;
}

/** The colour of the first hour of §4.2, shared with the reply queue. */
function tone(seconds: number | null): string {
    if (seconds === null) {
        return '';
    }

    if (seconds >= 3600) {
        return 'text-destructive';
    }

    return seconds >= 900
        ? 'text-amber-600 dark:text-amber-400'
        : 'text-emerald-600 dark:text-emerald-400';
}

function accent(seconds: number): string {
    if (seconds >= 3600) {
        return 'border-l-2 border-l-destructive/70';
    }

    return seconds >= 900
        ? 'border-l-2 border-l-amber-500/70'
        : 'border-l-2 border-l-emerald-500/70';
}

Today.layout = {
    breadcrumbs: [{ title: 'Today', href: todayIndex() }],
};
