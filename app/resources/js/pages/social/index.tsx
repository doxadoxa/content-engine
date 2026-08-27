import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    CircleDashed,
    Image as ImageIcon,
    Loader,
    Lock,
    MoveRight,
    Target,
} from 'lucide-react';
import { SocialTabs } from '@/components/social-tabs';
import { Button } from '@/components/ui/button';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { index } from '@/routes/social';
import { plan as socialPlan } from '@/routes/social';
import { show as showPost } from '@/routes/social/posts';

type Goal = {
    kpi: string;
    kpi_label: string;
    unit: string;
    target: number;
    cadence: number;
    weeks: Array<{ objective: string }>;
    confirmed: boolean;
    week_of: number | null;
    total_weeks: number;
    /** Null when nothing connected can measure this KPI. */
    progress: number | null;
    /** What would make it measurable, when nothing can. */
    needs: string | null;
};

type Card = {
    id: string;
    column: string;
    date: string;
    title: string;
    kind: string;
    kind_label: string;
    pillar: string;
    thesis: string;
    /** What it will be made as — a carousel, a single image, plain text. */
    content_format_label: string;
    /** Whether a person chose that, or the kind merely implies it. */
    format_chosen: boolean;
    channels: string[];
    production: Record<string, { format: string; visual: string }>;
    drafted: number;
    planned: number;
    items: Array<{ id: string; channel: string; state: string }>;
};

type Props = {
    month: string;
    label: string;
    previous: string;
    next: string;
    has_plan: boolean;
    goal: Goal | null;
    progress: { shipped: number; planned: number };
    columns: Record<string, Card[]>;
};

const COLUMNS = [
    {
        key: 'in_progress',
        label: 'In progress',
        hint: 'Being written, or waiting for you',
        empty: 'Nothing is being worked on right now.',
        icon: Loader,
    },
    {
        key: 'todo',
        label: 'To do',
        hint: 'Planned, nothing written',
        empty: 'Nothing is waiting to be started.',
        icon: CircleDashed,
    },
    {
        key: 'done',
        label: 'Done',
        hint: 'Every channel approved',
        empty: 'No completed posts yet.',
        icon: CircleCheck,
    },
] as const;

/**
 * The social surface's Overview.
 *
 * A goal above a board, which is the whole of the change. The Studio could
 * describe a month in detail and never say what it was for, so "accept this
 * proposal" was a decision with nothing riding on it; and every action on it
 * was scoped to a month, so there was never an answer to "what do I do now".
 * The header answers the first question and the three columns answer the
 * second.
 */
export default function SocialOverview({
    month,
    label,
    previous,
    next,
    has_plan: hasPlan,
    goal,
    progress,
    columns,
}: Props) {
    return (
        <>
            <Head title={`Social — ${label}`} />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Social"
                    context={
                        goal?.confirmed
                            ? `Week ${goal.week_of ?? goal.total_weeks} of ${goal.total_weeks}`
                            : 'No goal set'
                    }
                    title={label}
                    description="See this month’s goal, progress, and remaining work. Nothing publishes from this screen."
                    actions={
                        <>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Previous month"
                                className="rounded-full bg-muted/55"
                                onClick={() =>
                                    router.get(
                                        index({ query: { month: previous } }),
                                    )
                                }
                            >
                                <ChevronLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Next month"
                                className="rounded-full bg-muted/55"
                                onClick={() =>
                                    router.get(
                                        index({ query: { month: next } }),
                                    )
                                }
                            >
                                <ChevronRight
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </Button>
                        </>
                    }
                />

                <SocialTabs current="overview" month={month} />

                {goal?.confirmed ? (
                    <GoalHeader goal={goal} progress={progress} />
                ) : (
                    <NoGoal month={month} goal={goal} />
                )}

                {hasPlan ? (
                    <Board columns={columns} />
                ) : (
                    <NoPlan month={month} />
                )}
            </WorkspacePage>
        </>
    );
}

/**
 * Three numbers, and only the ones this deployment can source.
 *
 * The middle one — posts signed off against what the cadence promised — is
 * arithmetic on our own rows and is always true. The KPI figure is not: nothing
 * in this engine reads a follower count or an impression back yet, so it shows
 * the precondition instead of a confident zero.
 */
function GoalHeader({
    goal,
    progress,
}: {
    goal: Goal;
    progress: { shipped: number; planned: number };
}) {
    const pct =
        progress.planned > 0
            ? Math.min(
                  100,
                  Math.round((progress.shipped / progress.planned) * 100),
              )
            : 0;

    return (
        <section
            className="grid gap-5 rounded-[1.5rem] bg-card/85 p-5 shadow-[0_1px_2px_rgba(23,53,47,0.03),0_16px_42px_rgba(23,53,47,0.05)] sm:grid-cols-3 sm:gap-6 sm:p-6"
            aria-label="This month's goal"
        >
            <Figure
                label="Week"
                value={`${goal.week_of ?? goal.total_weeks} of ${goal.total_weeks}`}
                hint={`${goal.cadence} posts a week`}
            />

            <div>
                <p className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                    Signed off
                </p>
                <p className="mt-1.5 text-2xl font-semibold tracking-tight tabular-nums">
                    {pct}%
                </p>
                <div
                    className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-valuenow={pct}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label="Posts signed off this month"
                >
                    <div
                        className="h-full rounded-full bg-emerald-500 transition-[width]"
                        style={{ width: `${pct}%` }}
                    />
                </div>
                <p className="mt-1.5 text-xs text-muted-foreground">
                    {progress.shipped} of {progress.planned} posts
                </p>
            </div>

            <div>
                <p className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                    {goal.kpi_label}
                </p>
                {goal.progress === null ? (
                    <>
                        <p className="mt-1.5 flex items-center gap-1.5 text-2xl font-semibold tracking-tight text-muted-foreground">
                            <Lock className="size-4" aria-hidden="true" />
                            <span className="text-base font-medium">
                                Not measurable yet
                            </span>
                        </p>
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            {goal.needs}
                        </p>
                    </>
                ) : (
                    <>
                        <p className="mt-1.5 text-2xl font-semibold tracking-tight tabular-nums">
                            {goal.progress}
                            <span className="text-base font-normal text-muted-foreground">
                                {' '}
                                / {goal.target}
                            </span>
                        </p>
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            {goal.unit}
                        </p>
                    </>
                )}
            </div>
        </section>
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
        <div>
            <p className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1.5 text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            <p className="mt-1.5 text-xs text-muted-foreground">{hint}</p>
        </div>
    );
}

/**
 * No goal yet — a signpost, not a form.
 *
 * This used to be three KPI cards, a target field prefilled with 500 and a
 * cadence prefilled with 3. It asked the operator a question whose honest
 * answer, on a first month, is "I don't know — what is realistic?", and the
 * prefilled numbers then answered it for them. A month was measured for four
 * weeks against a figure nobody had reasoned about, which is worse than having
 * no figure at all.
 *
 * The assistant proposes it now, sized against what the account has actually
 * been publishing, and the operator approves or overrules it on Plan — where the
 * argument for the number sits next to the number. So this is a link.
 *
 * Two states, because "the assistant has not been asked yet" and "it has
 * answered and is waiting for you" are different jobs, and only the second is
 * one click from done.
 */
function NoGoal({ month, goal }: { month: string; goal: Goal | null }) {
    return (
        <section
            className="rounded-[1.5rem] bg-card/85 p-5 shadow-[0_1px_2px_rgba(23,53,47,0.03),0_16px_42px_rgba(23,53,47,0.05)] sm:p-6"
            aria-label="This month has no goal yet"
        >
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-violet-500/12 text-violet-600 dark:text-violet-300">
                        <Target className="size-4" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <h2 className="font-semibold tracking-tight">
                            {goal === null
                                ? 'This month has no goal yet'
                                : `${goal.kpi_label} by ${goal.target.toLocaleString()} — waiting for you`}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {goal === null
                                ? 'Build the month on Plan and the assistant will size one from what you have been publishing.'
                                : `It proposed ${goal.cadence} posts a week to get there. Approve it and this header starts counting.`}
                        </p>
                    </div>
                </div>

                <Button asChild variant={goal === null ? 'outline' : 'default'}>
                    <Link href={socialPlan({ query: { month } })}>
                        {goal === null ? 'Go to Plan' : 'Review it on Plan'}
                        <MoveRight className="size-4" aria-hidden="true" />
                    </Link>
                </Button>
            </div>
        </section>
    );
}

function Board({ columns }: { columns: Record<string, Card[]> }) {
    const inProgress = columns.in_progress?.length ?? 0;
    const done = columns.done?.length ?? 0;
    const visibleColumns =
        done === 0
            ? COLUMNS.filter((column) => column.key !== 'done')
            : COLUMNS;

    return (
        <section className="min-w-0" aria-labelledby="social-work-queue">
            <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2
                        id="social-work-queue"
                        className="text-lg font-semibold tracking-tight"
                    >
                        Work queue
                    </h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {inProgress > 0
                            ? `Start with the ${inProgress} ${inProgress === 1 ? 'post' : 'posts'} already in progress.`
                            : 'Choose the next planned post to start.'}
                    </p>
                </div>
                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <CircleCheck
                        className="size-3.5 text-emerald-500"
                        aria-hidden="true"
                    />
                    {done} completed this month
                </p>
            </div>

            <div
                className={`grid min-w-0 gap-4 ${
                    done === 0 ? 'lg:grid-cols-2' : 'lg:grid-cols-3'
                }`}
            >
                {visibleColumns.map(
                    ({ key, label, hint, empty, icon: Icon }) => {
                        const cards = columns[key] ?? [];
                        const tone =
                            key === 'in_progress'
                                ? 'bg-amber-500/[0.08] dark:bg-amber-400/[0.06]'
                                : key === 'done'
                                  ? 'bg-emerald-500/[0.07] dark:bg-emerald-400/[0.05]'
                                  : 'bg-muted/40';
                        const iconTone =
                            key === 'in_progress'
                                ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300'
                                : key === 'done'
                                  ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                                  : 'bg-background/75 text-muted-foreground';
                        const order =
                            key === 'todo'
                                ? 'order-2 lg:order-1'
                                : key === 'in_progress'
                                  ? 'order-1 lg:order-2'
                                  : 'order-3';

                        return (
                            <section
                                key={key}
                                className={`${tone} ${order} flex min-w-0 flex-col rounded-[1.5rem] p-2.5 sm:p-3`}
                                aria-label={label}
                            >
                                <header className="flex items-start justify-between gap-3 px-1.5 py-2">
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <span
                                            className={`${iconTone} flex size-8 shrink-0 items-center justify-center rounded-xl`}
                                        >
                                            <Icon
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <div className="min-w-0">
                                            <h3 className="text-sm font-semibold">
                                                {label}
                                            </h3>
                                            <p className="text-xs leading-snug text-muted-foreground">
                                                {hint}
                                            </p>
                                        </div>
                                    </div>
                                    <span className="flex min-w-7 shrink-0 justify-center rounded-full bg-background/75 px-2 py-1 text-xs font-semibold tabular-nums">
                                        {cards.length}
                                    </span>
                                </header>

                                <div className="grid min-w-0 gap-2.5 pt-1">
                                    {cards.length === 0 ? (
                                        <div className="grid justify-items-center gap-2 px-3 py-8 text-center text-xs text-muted-foreground">
                                            <CircleCheck
                                                className="size-5 text-emerald-500/70"
                                                aria-hidden="true"
                                            />
                                            <p>{empty}</p>
                                        </div>
                                    ) : (
                                        cards.map((card) => (
                                            <BoardCard
                                                key={card.id}
                                                card={card}
                                            />
                                        ))
                                    )}
                                </div>
                            </section>
                        );
                    },
                )}
            </div>
        </section>
    );
}

/**
 * One idea, with the rationale it was planned for.
 *
 * The thesis is on the card rather than one click in, because the operator's
 * decision on a To Do item is whether to make it at all — and that is a
 * judgement about the reason, not about the headline.
 */
function BoardCard({ card }: { card: Card }) {
    const date = new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${card.date}T00:00:00Z`));

    return (
        <article className="min-w-0 rounded-2xl bg-card p-4 shadow-[0_1px_2px_rgba(23,53,47,0.04),0_8px_24px_rgba(23,53,47,0.05)]">
            <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                <span className="rounded-full bg-muted/70 px-2 py-1 text-[11px] font-medium">
                    {card.kind_label}
                </span>
                {/*
                    What it will be *made as*, beside what it is about. The
                    board knew this all along — ActionBoard has sent
                    `content_format_label` since formats existed — and only the
                    Create shelf showed it, so an operator scanning the board
                    could not tell a carousel from a text post without opening
                    each card. Outlined rather than filled while the kind is
                    merely implying it, so a format somebody actually chose
                    reads as a decision.
                */}
                <span
                    className={`rounded-full px-2 py-1 text-[11px] font-medium ${
                        card.format_chosen
                            ? 'bg-violet-500/12 text-violet-700 dark:text-violet-300'
                            : 'border border-border text-muted-foreground'
                    }`}
                >
                    {card.content_format_label}
                </span>
                <span className="text-[11px] font-medium text-muted-foreground">
                    {date}
                </span>
            </div>

            <h4 className="mt-2.5 text-sm leading-snug font-semibold tracking-tight">
                {card.title}
            </h4>
            <p className="mt-1.5 line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                {card.thesis}
            </p>

            <div className="mt-3.5 flex min-w-0 flex-wrap items-center justify-between gap-2">
                <span className="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                    <ImageIcon className="size-3 shrink-0" aria-hidden="true" />
                    <span className="truncate capitalize">
                        {card.channels.join(', ')}
                    </span>
                </span>
                {/* Green when the work is *finished*, not when it is merely
                    written. "2 of 2 drafted" in the same emerald as a settled
                    card said the job was done over two posts still waiting for
                    a person. */}
                <span
                    className={`shrink-0 rounded-full px-2 py-1 text-[11px] font-semibold tabular-nums ${
                        settled(card)
                            ? 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-300'
                            : 'bg-muted/70 text-muted-foreground'
                    }`}
                >
                    {settled(card)
                        ? `${card.planned} of ${card.planned} signed off`
                        : `${card.drafted} of ${card.planned} drafted`}
                </span>
            </div>

            {/* One row per written channel, because the composer is about a
                post rather than about an idea: a Threads draft and an
                Instagram draft of the same Tuesday are approved separately and
                may disagree about whether they are ready. */}
            {card.items.length > 0 && (
                <ul className="mt-3 grid gap-1.5">
                    {card.items.map((item) => (
                        <li key={item.id}>
                            <Link
                                href={showPost(item.id)}
                                className="flex min-h-9 items-center justify-between gap-2 rounded-xl bg-muted/55 px-2.5 py-1.5 text-xs transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                            >
                                <span className="truncate capitalize">
                                    {item.channel}
                                </span>
                                {/* A draft and an approved post read
                                    identically in the same grey, and they are
                                    opposite facts: one is a job and the other
                                    is a receipt. The colour carries which. */}
                                <span
                                    className={`flex shrink-0 items-center gap-1.5 text-[11px] font-medium ${stateTone(
                                        item.state,
                                    )}`}
                                >
                                    {stateLabel(item.state)}
                                    <MoveRight
                                        className="size-3 opacity-60"
                                        aria-hidden="true"
                                    />
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </article>
    );
}

function NoPlan({ month }: { month: string }) {
    return (
        <section
            className={`${workspacePanelClass} grid justify-items-center gap-3 p-10 text-center`}
        >
            <span className="flex size-12 items-center justify-center rounded-full bg-orange-500/10 text-orange-600 dark:text-orange-300">
                <CalendarDays className="size-5" aria-hidden="true" />
            </span>
            <h2 className="text-lg font-semibold tracking-tight">
                This month has no plan yet
            </h2>
            <p className="max-w-md text-sm leading-relaxed text-muted-foreground">
                The board fills from the month’s ideas. Build a plan in the
                Studio and every idea in it lands in To do.
            </p>
            <Button asChild variant="outline">
                <Link href={socialPlan({ query: { month } })}>
                    Open the Studio
                </Link>
            </Button>
        </section>
    );
}

/**
 * Whether every channel of this idea is finished with.
 *
 * "Drafted" is not the finish line — a written post still needs a person — so
 * this is what the card's badge turns green on.
 */
function settled(card: Card): boolean {
    return (
        card.items.length > 0 &&
        card.items.length === card.planned &&
        card.items.every(
            (item) => item.state === 'approved' || item.state === 'published',
        )
    );
}

/**
 * What a state is *for the reader*, which is not always its name.
 *
 * `draft` is the only one that asks for something, so it is the only one that
 * says so. The rest are states of the machine, and a person scanning a board
 * wants to know which rows are theirs.
 */
function stateLabel(state: string): string {
    return (
        {
            draft: 'Review',
            approved: 'Approved',
            published: 'Published',
            queued: 'Queued',
            generating: 'Writing…',
            refreshing: 'Rewriting…',
        }[state] ?? state
    );
}

/** Amber asks, emerald reassures, muted is the machine working. */
function stateTone(state: string): string {
    return (
        {
            draft: 'text-amber-600 dark:text-amber-400',
            approved: 'text-emerald-700 dark:text-emerald-400',
            published: 'text-emerald-700/70 dark:text-emerald-400/70',
        }[state] ?? 'text-muted-foreground'
    );
}
