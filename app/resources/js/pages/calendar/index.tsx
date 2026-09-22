import { Head, Link, router, usePoll } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import { publicationLabels } from '@/components/article-publication';
import type { ArticlePublication } from '@/components/article-publication';
import { ContentActions } from '@/components/content-actions';
import type { ArticleWorkflow } from '@/components/content-actions';
import { ContextualAssistant } from '@/components/contextual-assistant';
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
    calendar_date: string | null;
    publication: ArticlePublication;
    id: string;
    title: string;
    state: string;
    state_label: string;
    is_live: boolean;
    type_label: string;
    target_query: string | null;
    topic_difficulty: number | null;
    topic_volume: number | null;
    scheduled_for: string | null;
    locales: string[];
};

type Props = {
    article_workflow: ArticleWorkflow;
    timezone: string;
    mode: 'automatic' | 'review_first';
    planning: boolean;
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
    article_workflow: workflow,
    timezone,
    mode,
    planning,
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
    usePoll(15000, { only: ['units', 'unscheduled', 'plan', 'planning'] });
    const today = new Date().toLocaleDateString('en-CA', {
        timeZone: timezone,
    });

    const byDay = new Map<number, Unit[]>();

    for (const unit of units) {
        if (unit.calendar_date === null) {
            continue;
        }

        const day = Number(unit.calendar_date.slice(8, 10));
        byDay.set(day, [...(byDay.get(day) ?? []), unit]);
    }

    const summary = calendarSummary(units);

    return (
        <>
            <Head title={`Calendar — ${label}`} />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Calendar"
                    context={timezone}
                    title={label}
                    description="Your upcoming articles, from first idea to publication. Open any article to review it or change its schedule."
                    actions={
                        <>
                            <ContentActions
                                month={month}
                                planning={planning}
                                primary={workflow.ready ? 'plan' : 'none'}
                            />
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

                <section
                    className={`${workspacePanelClass} flex flex-wrap items-center justify-between gap-4 p-5`}
                >
                    <div>
                        <h2 className="font-medium">
                            {workflow.ready
                                ? mode === 'automatic'
                                    ? 'Publish automatically on schedule'
                                    : 'Review before publishing'
                                : 'Publishing setup needed'}
                        </h2>
                        <p className="mt-1 text-sm leading-6 text-muted-foreground">
                            {workflow.message}
                        </p>
                    </div>
                    {!workflow.ready && (
                        <Button asChild size="sm" className="shrink-0">
                            <Link href={workflow.action}>
                                {workflow.action_label}
                            </Link>
                        </Button>
                    )}
                </section>

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
                                    {summary.scheduled} scheduled ·{' '}
                                    {summary.suggested}{' '}
                                    {summary.suggested === 1
                                        ? 'article on a suggested date'
                                        : 'articles on suggested dates'}
                                    {summary.other > 0 &&
                                        ` · ${summary.other} other ${summary.other === 1 ? 'article' : 'articles'}`}
                                </p>
                            </div>
                        </div>
                        <StateCounts units={units} />
                    </section>
                )}

                <MobileAgenda units={units} today={today} />

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
                                    date={`${month.slice(0, 8)}${String(
                                        index + 1,
                                    ).padStart(2, '0')}`}
                                    today={today}
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
                                Not scheduled yet
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Open an article to choose its publication date
                                and review preference.
                            </p>
                        </div>
                        <div className="flex min-w-0 flex-wrap gap-2">
                            {unscheduled.map((unit) => (
                                <Badge
                                    key={unit.id}
                                    variant="outline"
                                    className="max-w-full whitespace-normal"
                                >
                                    <Link
                                        href={show(unit.id)}
                                        className="break-words hover:underline"
                                    >
                                        {unit.title}
                                    </Link>
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
                <ContextualAssistant
                    context={`Reviewing the website content calendar for ${label}. It has ${summary.scheduled} committed publications, ${summary.suggested} suggested dates, and ${unscheduled.length} articles without a date.`}
                    label="Discuss this plan"
                />
            </WorkspacePage>
        </>
    );
}

/** A chronological view that fits a phone without shrinking seven columns. */
function MobileAgenda({ units, today }: { units: Unit[]; today: string }) {
    if (units.length === 0) {
        return null;
    }

    const groups = new Map<string, Unit[]>();

    for (const unit of units) {
        const date = unit.calendar_date ?? 'Unscheduled';
        groups.set(date, [...(groups.get(date) ?? []), unit]);
    }

    return (
        <section className="flex flex-col gap-4 sm:hidden" aria-label="Agenda">
            {[...groups.entries()].map(([date, dayUnits]) => {
                const suggested = dayUnits.every(isSuggestedDate);

                return (
                    <div
                        key={date}
                        className="flex min-w-0 flex-col gap-3 rounded-[1.25rem] border bg-card/80 p-4 shadow-sm backdrop-blur-sm"
                    >
                        <h2 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {date === 'Unscheduled'
                                ? date
                                : `${suggested ? 'Suggested date · ' : ''}${new Intl.DateTimeFormat(
                                      undefined,
                                      {
                                          weekday: 'short',
                                          month: 'short',
                                          day: 'numeric',
                                          timeZone: 'UTC',
                                      },
                                  ).format(new Date(`${date}T00:00:00Z`))}`}
                        </h2>
                        {dayUnits.map((unit) => (
                            <UnitCard
                                key={unit.id}
                                unit={unit}
                                pastSuggestion={
                                    isSuggestedDate(unit) && date < today
                                }
                            />
                        ))}
                    </div>
                );
            })}
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
        const seen = counts.get(unit.publication.status);

        counts.set(unit.publication.status, {
            label: publicationLabels[unit.publication.status],
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

function calendarSummary(units: Unit[]) {
    return units.reduce(
        (summary, unit) => {
            if (unit.publication.schedule?.status === 'active') {
                summary.scheduled += 1;
            } else if (isSuggestedDate(unit)) {
                summary.suggested += 1;
            } else {
                summary.other += 1;
            }

            return summary;
        },
        { scheduled: 0, suggested: 0, other: 0 },
    );
}

function isSuggestedDate(unit: Unit) {
    return (
        unit.publication.schedule === null &&
        unit.publication.status !== 'published'
    );
}

function DayCell({
    day,
    date,
    today,
    units,
    isToday,
}: {
    day: number;
    date: string;
    today: string;
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
                <UnitCard
                    key={unit.id}
                    unit={unit}
                    pastSuggestion={isSuggestedDate(unit) && date < today}
                />
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
function UnitCard({
    unit,
    pastSuggestion = false,
}: {
    unit: Unit;
    pastSuggestion?: boolean;
}) {
    const suggested = isSuggestedDate(unit);

    return (
        <Link
            href={show(unit.id)}
            className={`group flex flex-col gap-2 rounded-xl border bg-card/90 p-2.5 shadow-sm transition-all hover:-translate-y-0.5 hover:border-violet-500/35 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${suggested ? 'border-dashed border-amber-500/45 bg-amber-50/30 dark:bg-amber-950/10' : ''}`}
        >
            <span className="line-clamp-3 text-sm leading-snug font-medium">
                {unit.title}
            </span>
            <StatePill
                state={unit.publication.status}
                label={publicationLabels[unit.publication.status]}
            />
            <span className="text-xs leading-5 text-muted-foreground">
                {publicationTiming(unit, pastSuggestion)}
            </span>
        </Link>
    );
}

function publicationTiming(unit: Unit, pastSuggestion: boolean) {
    const schedule = unit.publication.schedule;

    if (schedule === null) {
        if (!isSuggestedDate(unit)) {
            return publicationLabels[unit.publication.status];
        }

        return pastSuggestion
            ? 'Suggested date in the past · choose a new date to schedule'
            : 'Suggested date · not scheduled to publish';
    }

    return schedule.status === 'active'
        ? `${schedule.local_time} · ${schedule.mode === 'automatic' ? 'Automatic' : 'Review first'}`
        : `${schedule.local_time} · ${publicationLabels[unit.publication.status]}`;
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
            scheduled: 'border-sky-500/40 text-sky-600 dark:text-sky-400',
            needs_review:
                'border-amber-500/40 text-amber-700 dark:text-amber-400',
            planned: 'border-amber-500/40 text-amber-700 dark:text-amber-400',
            writing:
                'border-violet-500/40 text-violet-600 dark:text-violet-400',
            publishing:
                'border-violet-500/40 text-violet-600 dark:text-violet-400',
            blocked: 'border-rose-500/40 text-rose-700 dark:text-rose-400',
            paused: 'border-amber-500/40 text-amber-700 dark:text-amber-400',
            canceled: 'border-muted-foreground/30 text-muted-foreground',
            unscheduled: 'border-muted-foreground/30 text-muted-foreground',
        }[state] ?? 'border-muted-foreground/30 text-muted-foreground';

    const dot =
        {
            published: 'bg-emerald-500',
            scheduled: 'bg-sky-500',
            needs_review: 'bg-amber-500',
            planned: 'bg-amber-500',
            writing: 'bg-violet-500',
            publishing: 'bg-violet-500',
            blocked: 'bg-rose-500',
            paused: 'bg-amber-500',
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
                    Use “Plan my content” to let Avyo research topics and
                    prepare your calendar, or create an article about a customer
                    question.
                </CardDescription>
            </CardHeader>
        </Card>
    );
}

Calendar.layout = {
    breadcrumbs: [{ title: 'Calendar', href: index() }],
};
