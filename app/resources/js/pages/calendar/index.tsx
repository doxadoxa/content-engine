import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    FileText,
} from 'lucide-react';
import { PlanViews } from '@/components/plan-views';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { index } from '@/routes/calendar';
import { show } from '@/routes/content';

type Unit = {
    id: string;
    title: string;
    state: string;
    state_label: string;
    is_live: boolean;
    type_label: string;
    topic_difficulty: number | null;
    topic_volume: number | null;
    scheduled_for: string | null;
    locales: string[];
};

type Props = {
    month: string;
    label: string;
    previous: string;
    next: string;
    days_in_month: number;
    starts_on: number;
    plan: { id: string; status: string; approved: boolean } | null;
    units: Unit[];
    unscheduled: Unit[];
};

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/**
 * A month of planned work.
 *
 * The grid is built from `starts_on` and `days_in_month` handed over by the
 * server rather than re-derived here from a date string: two implementations of
 * "which weekday does the 1st fall on" is one more than a calendar needs, and
 * they disagree in exactly one time zone.
 */
export default function Calendar({
    month,
    label,
    previous,
    next,
    days_in_month,
    starts_on,
    plan,
    units,
    unscheduled,
}: Props) {
    // Compared as a date string rather than by constructing Dates: the month
    // comes from the server as YYYY-MM-DD, and `en-CA` is the locale that
    // formats that way. Both sides keep their dashes — stripping them from one
    // and not the other is a comparison that is never true.
    const today = new Date().toLocaleDateString('en-CA');

    const byDay = new Map<number, Unit[]>();

    for (const unit of units) {
        if (unit.scheduled_for === null) {
            continue;
        }

        const day = Number(unit.scheduled_for.slice(8, 10));
        byDay.set(day, [...(byDay.get(day) ?? []), unit]);
    }

    return (
        <>
            <Head title={`Calendar — ${label}`} />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Content plan"
                    context={
                        plan === null
                            ? 'No plan generated'
                            : plan.approved
                              ? 'Approved plan'
                              : `${plan.status} plan`
                    }
                    title={label}
                    description="What this project is scheduled to publish, and where every article is in the workflow."
                    actions={
                        <>
                            <PlanViews active="calendar" />
                            {plan !== null && (
                                <Badge
                                    variant={
                                        plan.approved ? 'default' : 'secondary'
                                    }
                                    className="hidden h-9 rounded-full px-3 sm:inline-flex"
                                >
                                    plan {plan.status}
                                </Badge>
                            )}
                            <Button
                                variant="outline"
                                size="icon"
                                className="rounded-full bg-background/70 shadow-sm"
                                aria-label="Previous month"
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
                                variant="outline"
                                size="icon"
                                className="rounded-full bg-background/70 shadow-sm"
                                aria-label="Next month"
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

                {units.length > 0 && (
                    <section
                        className={`${workspacePanelClass} flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6`}
                        aria-labelledby="month-overview"
                    >
                        <div className="flex items-center gap-3">
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300">
                                <CalendarDays
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </span>
                            <div>
                                <h2
                                    id="month-overview"
                                    className="font-semibold tracking-tight"
                                >
                                    Month at a glance
                                </h2>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {units.length}{' '}
                                    {units.length === 1
                                        ? 'article'
                                        : 'articles'}{' '}
                                    across {byDay.size}{' '}
                                    {byDay.size === 1 ? 'date' : 'dates'}
                                </p>
                            </div>
                        </div>
                        <StateCounts units={units} />
                    </section>
                )}

                <MobileAgenda units={units} />

                <Card
                    className={`hidden max-w-full overflow-x-auto p-0 sm:block ${workspacePanelClass}`}
                >
                    <div className="grid min-w-3xl grid-cols-7 border-b bg-muted/20 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {WEEKDAYS.map((day) => (
                            <div key={day} className="p-2">
                                {day}
                            </div>
                        ))}
                    </div>
                    <div className="grid min-w-3xl grid-cols-7">
                        {/* Blank cells before the 1st, so the month lines up
                            with the weekday header. */}
                        {Array.from({ length: starts_on - 1 }).map(
                            (_, index) => (
                                <div
                                    key={`pad-${index}`}
                                    className="min-h-40 border-r border-b bg-muted/30"
                                />
                            ),
                        )}
                        {Array.from({ length: days_in_month }).map(
                            (_, index) => (
                                <DayCell
                                    key={index + 1}
                                    day={index + 1}
                                    units={byDay.get(index + 1) ?? []}
                                    isToday={
                                        `${month.slice(0, 8)}${String(
                                            index + 1,
                                        ).padStart(2, '0')}` === today
                                    }
                                />
                            ),
                        )}
                    </div>
                </Card>

                {unscheduled.length > 0 && (
                    <section
                        className={`${workspacePanelClass} flex flex-col gap-4 px-5 py-5 sm:px-6`}
                    >
                        <div>
                            <h2 className="font-semibold tracking-tight">
                                Ideas with no date
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Keep these visible until they earn a place in
                                the calendar.
                            </p>
                        </div>
                        <div className="flex min-w-0 flex-wrap gap-2">
                            {unscheduled.map((unit) => (
                                <Badge
                                    key={unit.id}
                                    variant="outline"
                                    className="max-w-full whitespace-normal"
                                >
                                    <span className="break-words">
                                        {unit.title}
                                    </span>
                                    {unit.topic_volume !== null && (
                                        <span className="ml-1 opacity-60">
                                            {unit.topic_volume.toLocaleString()}
                                            /mo
                                        </span>
                                    )}
                                </Badge>
                            ))}
                        </div>
                    </section>
                )}

                {units.length === 0 && <EmptyMonth />}
            </WorkspacePage>
        </>
    );
}

/** A chronological view that fits a phone without shrinking seven columns. */
function MobileAgenda({ units }: { units: Unit[] }) {
    if (units.length === 0) {
        return null;
    }

    const groups = new Map<string, Unit[]>();

    for (const unit of units) {
        const date = unit.scheduled_for ?? 'Unscheduled';
        groups.set(date, [...(groups.get(date) ?? []), unit]);
    }

    return (
        <section className="flex flex-col gap-4 sm:hidden" aria-label="Agenda">
            {[...groups.entries()].map(([date, dayUnits]) => (
                <div
                    key={date}
                    className="flex min-w-0 flex-col gap-3 rounded-[1.25rem] border bg-card/80 p-4 shadow-sm backdrop-blur-sm"
                >
                    <h2 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {date === 'Unscheduled'
                            ? date
                            : new Intl.DateTimeFormat(undefined, {
                                  weekday: 'short',
                                  month: 'short',
                                  day: 'numeric',
                                  timeZone: 'UTC',
                              }).format(new Date(`${date}T00:00:00Z`))}
                    </h2>
                    {dayUnits.map((unit) => (
                        <UnitCard key={unit.id} unit={unit} />
                    ))}
                </div>
            ))}
        </section>
    );
}

/**
 * How the month is going, in one line.
 *
 * Counted from the units on the page rather than queried separately, so the
 * summary and the squares under it can never disagree.
 */
function StateCounts({ units }: { units: Unit[] }) {
    const counts = new Map<string, { label: string; count: number }>();

    for (const unit of units) {
        const seen = counts.get(unit.state);

        counts.set(unit.state, {
            label: unit.state_label,
            count: (seen?.count ?? 0) + 1,
        });
    }

    if (counts.size === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {[...counts.entries()].map(([state, { label, count }]) => (
                <StatePill
                    key={state}
                    state={state}
                    label={`${count} ${label}`}
                />
            ))}
        </div>
    );
}

function DayCell({
    day,
    units,
    isToday,
}: {
    day: number;
    units: Unit[];
    isToday: boolean;
}) {
    return (
        <div className="flex min-h-40 flex-col gap-2 border-r border-b bg-background/20 p-2 transition-colors hover:bg-muted/20">
            <span
                className={
                    isToday
                        ? 'flex size-5 items-center justify-center rounded-full bg-primary text-xs font-medium text-primary-foreground'
                        : 'text-xs text-muted-foreground'
                }
            >
                {day}
            </span>
            {units.map((unit) => (
                <UnitCard key={unit.id} unit={unit} />
            ))}
        </div>
    );
}

/**
 * One planned unit, as a card.
 *
 * The three things a reviewer scans a month for — where it has got to, what it
 * is aiming at, and what shape it is — in the order they ask them. The state
 * leads because a month is read to find what needs doing.
 */
function UnitCard({ unit }: { unit: Unit }) {
    return (
        <Link
            href={show(unit.id)}
            className="group flex flex-col gap-2 rounded-xl border bg-card/90 p-2.5 shadow-sm transition-all hover:-translate-y-0.5 hover:border-violet-500/35 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <StatePill state={unit.state} label={unit.state_label} />

            <span className="line-clamp-2 text-sm leading-snug font-medium">
                {unit.title}
            </span>

            {(unit.topic_difficulty !== null || unit.topic_volume !== null) && (
                <span className="flex flex-col text-xs text-muted-foreground">
                    {unit.topic_difficulty !== null && (
                        <span>
                            Difficulty:{' '}
                            <span className="text-foreground">
                                {unit.topic_difficulty}
                            </span>
                        </span>
                    )}
                    {unit.topic_volume !== null && (
                        <span>
                            Volume:{' '}
                            <span className="text-foreground">
                                {unit.topic_volume.toLocaleString()}
                            </span>
                        </span>
                    )}
                </span>
            )}

            <span className="mt-auto flex items-center gap-1.5 border-t pt-2 text-xs text-muted-foreground">
                <FileText className="size-3.5 shrink-0" aria-hidden="true" />
                <span className="truncate group-hover:underline">
                    {unit.type_label} article
                </span>
                {unit.locales.length > 1 && (
                    <span className="ml-auto shrink-0">
                        {unit.locales.length} langs
                    </span>
                )}
            </span>
        </Link>
    );
}

/**
 * Where a unit has got to, as a dot and a word.
 *
 * Colour alone would not say it — a monochrome screen, or anybody who does not
 * distinguish amber from green, reads the word.
 */
function StatePill({ state, label }: { state: string; label: string }) {
    const tone =
        {
            published:
                'border-emerald-500/40 text-emerald-600 dark:text-emerald-400',
            approved: 'border-sky-500/40 text-sky-600 dark:text-sky-400',
            draft: 'border-amber-500/40 text-amber-600 dark:text-amber-400',
            generating:
                'border-violet-500/40 text-violet-600 dark:text-violet-400',
            refreshing: 'border-blue-500/40 text-blue-600 dark:text-blue-400',
        }[state] ?? 'border-muted-foreground/30 text-muted-foreground';

    const dot =
        {
            published: 'bg-emerald-500',
            approved: 'bg-sky-500',
            draft: 'bg-amber-500',
            generating: 'bg-violet-500',
            refreshing: 'bg-blue-500',
        }[state] ?? 'bg-muted-foreground/50';

    return (
        <span
            className={`inline-flex w-fit items-center gap-1.5 rounded-md border px-1.5 py-0.5 text-[10px] font-medium tracking-wide uppercase ${tone}`}
        >
            <span
                className={`size-1.5 rounded-full ${dot}`}
                aria-hidden="true"
            />
            {label}
        </span>
    );
}

function EmptyMonth() {
    return (
        <Card className={`${workspacePanelClass} py-12`}>
            <CardHeader className="items-center text-center">
                <CalendarDays
                    className="size-8 text-muted-foreground"
                    aria-hidden="true"
                />
                <CardTitle>Nothing scheduled this month</CardTitle>
                <CardDescription>
                    Run the planning pipeline to turn the idea pool into a
                    month.
                </CardDescription>
            </CardHeader>
        </Card>
    );
}

Calendar.layout = {
    breadcrumbs: [{ title: 'Calendar', href: index() }],
};
