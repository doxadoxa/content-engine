import { Head } from '@inertiajs/react';
import { AlertTriangle, Eye, Sparkles } from 'lucide-react';
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
import { index } from '@/routes/visibility';

type LocaleRow = {
    locale: string;
    score: number | null;
    answered: number;
    mentions: number;
    prompts: number;
};

type Props = {
    summary: {
        score: number | null;
        monitored_prompts: number;
        answered: number;
        mentions: number;
        declined: number;
        money_spent: number;
        last_asked_on: string | null;
        configured: boolean;
    };
    by_locale: LocaleRow[];
    by_platform: {
        platform: string;
        label: string;
        score: number | null;
        answered: number;
        mentions: number;
        /** The day this assistant's freshest answers were taken. */
        last_asked_on: string | null;
        /** Its freshest reading trails the rest of the panel by enough to say so. */
        stale: boolean;
    }[];
    prompts: {
        id: string;
        text: string;
        locale: string;
        intent: string;
        intent_label: string;
        mentioned_in: string[];
        answered: number;
        declined: number;
    }[];
    sources: { host: string; citations: number; is_aggregator: boolean }[];
    competitors: { host: string; citations: number }[];
    project: { name: string; locales: string[] };
};

const INTENT_TONE: Record<string, string> = {
    buying: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    comparison:
        'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
    learning: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
};

/**
 * What the assistants say about the brand.
 *
 * The per-locale breakdown sits directly under the headline rather than in a tab,
 * because the headline on its own is the number that misled us: a project can
 * read 0% overall while being named in every Russian answer, and averaging the
 * languages together hides the one that is sending customers.
 */
export default function Visibility({
    summary,
    by_locale,
    by_platform,
    prompts,
    sources,
    competitors,
    project,
}: Props) {
    const unmeasuredLocales = by_locale.filter((row) => row.answered === 0);

    return (
        <>
            <Head title="Prompt analysis" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="AI visibility"
                    context={
                        summary.last_asked_on === null
                            ? 'Awaiting first sweep'
                            : `Measured ${summary.last_asked_on}`
                    }
                    title="Prompt analysis"
                    description="Where your brand appears when someone asks an assistant, and which sources it cites instead."
                />

                {!summary.configured && (
                    <Card className="rounded-[1.5rem] border-amber-300 bg-amber-50 shadow-none dark:border-amber-900 dark:bg-amber-950/40">
                        <CardHeader className="flex-row items-center gap-2">
                            <AlertTriangle className="size-4 text-amber-600" />
                            <CardDescription className="text-amber-900 dark:text-amber-200">
                                No assistant panel is connected, so nothing is
                                being asked. Set the DataForSEO credentials to
                                start measuring.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                <VisibilityHero
                    summary={summary}
                    byLocale={by_locale}
                    project={project}
                    unmeasuredLocales={unmeasuredLocales}
                />

                <Card className={workspacePanelClass}>
                    <CardHeader className="border-b pb-5">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <span className="flex size-8 items-center justify-center rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300">
                                <Sparkles
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </span>
                            Monitored prompts
                        </CardTitle>
                        <CardDescription>
                            {summary.last_asked_on
                                ? `Last asked ${summary.last_asked_on}. Cost so far $${summary.money_spent.toFixed(3)}.`
                                : 'Not asked yet.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {prompts.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No prompts yet. They are written on the first
                                visibility run, one set per language.
                            </p>
                        ) : (
                            <Table className="min-w-[760px]">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Prompt</TableHead>
                                        <TableHead className="w-20">
                                            Language
                                        </TableHead>
                                        <TableHead className="w-36">
                                            Intent
                                        </TableHead>
                                        <TableHead>
                                            {project.name} mentioned in
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {prompts.map((prompt) => (
                                        <TableRow key={prompt.id}>
                                            <TableCell className="max-w-md leading-relaxed whitespace-normal">
                                                {prompt.text}
                                            </TableCell>
                                            <TableCell className="max-w-xs whitespace-normal text-muted-foreground">
                                                {prompt.locale}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="secondary"
                                                    className={
                                                        INTENT_TONE[
                                                            prompt.intent
                                                        ]
                                                    }
                                                >
                                                    {prompt.intent_label}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {prompt.answered === 0
                                                    ? 'Not asked yet'
                                                    : prompt.mentioned_in
                                                            .length === 0
                                                      ? 'Not mentioned'
                                                      : prompt.mentioned_in.join(
                                                            ', ',
                                                        )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="text-sm">
                                Cited instead of you
                            </CardTitle>
                            <CardDescription>
                                Sites the assistants pointed at, directories
                                removed. These are the pages to be mentioned on
                                or to out-answer.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {competitors.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing yet.
                                </p>
                            ) : (
                                competitors.map((row) => (
                                    <Bar
                                        key={row.host}
                                        label={row.host}
                                        value={row.citations}
                                        max={competitors[0].citations}
                                    />
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="text-sm">
                                Top sources
                            </CardTitle>
                            <CardDescription>
                                Everything cited, including the directories — a
                                directory at the top means the assistants do not
                                know the businesses directly, and getting listed
                                there is the cheaper move.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {sources.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing yet.
                                </p>
                            ) : (
                                sources.map((row) => (
                                    <div
                                        key={row.host}
                                        className="flex items-center justify-between gap-2 text-sm"
                                    >
                                        <span className="truncate">
                                            {row.host}
                                            {row.is_aggregator && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2 text-[10px]"
                                                >
                                                    directory
                                                </Badge>
                                            )}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {row.citations}×
                                        </span>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>

                {by_platform.length > 0 && (
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="text-sm">
                                By assistant
                            </CardTitle>
                            {/*
                             * Said once at the top as well as per tile, because
                             * the number this changes the meaning of is the
                             * headline, and the headline is not in this card.
                             */}
                            {by_platform.some((row) => row.stale) && (
                                <CardDescription className="text-amber-600 dark:text-amber-400">
                                    Some assistants were last measured earlier
                                    than the rest, so the headline averages
                                    readings from different days.
                                </CardDescription>
                            )}
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {by_platform.map((row) => (
                                <div
                                    key={row.platform}
                                    className={`rounded-2xl border p-4 ${
                                        row.stale
                                            ? 'border-amber-500/40 bg-amber-500/5'
                                            : 'bg-muted/20'
                                    }`}
                                >
                                    <div className="text-xs text-muted-foreground">
                                        {row.label}
                                    </div>
                                    <div className="text-2xl font-semibold tabular-nums">
                                        {row.score === null
                                            ? '—'
                                            : `${row.score}%`}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {row.mentions} of {row.answered} answers
                                    </div>
                                    {/*
                                     * Only where it is behind. A date on every
                                     * tile is four dates that are the same on
                                     * every healthy panel, which is noise that
                                     * teaches people to stop reading them.
                                     */}
                                    {row.stale &&
                                        row.last_asked_on !== null && (
                                            <div className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                                Last answered{' '}
                                                {row.last_asked_on}, not in the
                                                latest sweep
                                            </div>
                                        )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </WorkspacePage>
        </>
    );
}

function VisibilityHero({
    summary,
    byLocale,
    project,
    unmeasuredLocales,
}: {
    summary: Props['summary'];
    byLocale: LocaleRow[];
    project: Props['project'];
    unmeasuredLocales: LocaleRow[];
}) {
    // Null is unmeasured; zero is a real result and must remain visible.
    const measured = summary.score !== null;

    return (
        <section
            className="relative isolate min-h-[30rem] overflow-hidden rounded-[1.75rem] border border-white/8 bg-[#17141f] text-white shadow-[0_24px_80px_rgba(26,20,43,0.22)]"
            aria-labelledby="visibility-overview"
        >
            <div
                className="pointer-events-none absolute -top-28 -right-24 -z-10 size-80 rounded-full bg-violet-500/30 blur-3xl"
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
                            id="visibility-overview"
                            className="flex items-center gap-2 text-xs font-medium tracking-[0.14em] text-violet-200 uppercase"
                        >
                            <Sparkles className="size-3.5" aria-hidden="true" />
                            Brand visibility
                        </h2>
                        <p className="mt-2 text-sm text-white/50">
                            {summary.last_asked_on
                                ? `Last measured ${summary.last_asked_on}`
                                : 'The first prompt sweep has not run yet'}
                        </p>
                    </div>
                    <Badge className="rounded-full border-white/10 bg-white/8 px-3 py-1 text-white">
                        {byLocale.length || project.locales.length}{' '}
                        {(byLocale.length || project.locales.length) === 1
                            ? 'prompt language'
                            : 'prompt languages'}
                    </Badge>
                </div>

                <div className="my-auto grid gap-10 py-10 md:grid-cols-[minmax(0,0.75fr)_minmax(0,1.25fr)] md:items-end">
                    <div>
                        <div
                            className="flex items-start font-semibold tracking-[-0.075em] tabular-nums"
                            aria-label={
                                measured
                                    ? `${summary.score} percent visibility`
                                    : 'Visibility not measured'
                            }
                        >
                            <Eye
                                className="mt-1 mr-3 size-5 text-violet-300"
                                aria-hidden="true"
                            />
                            <span className="text-[5.5rem] leading-[0.82] sm:text-[7rem]">
                                {measured ? summary.score : '—'}
                            </span>
                            {measured && (
                                <span className="ml-2 text-2xl text-violet-300">
                                    %
                                </span>
                            )}
                        </div>
                        <p className="mt-5 max-w-60 text-sm leading-6 text-white/55">
                            Share of answered prompts where {project.name} was
                            named.
                        </p>
                    </div>

                    <div>
                        <p className="mb-4 text-[11px] font-medium tracking-[0.14em] text-white/55 uppercase">
                            Visibility by language
                        </p>
                        {byLocale.length === 0 ? (
                            <div className="rounded-2xl border border-white/8 bg-white/[0.035] p-5 text-sm text-white/60">
                                No prompt results yet. Language performance will
                                appear here after the first sweep.
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {byLocale.map((row) => (
                                    <div key={row.locale}>
                                        <div className="mb-2 flex items-center justify-between gap-3 text-sm">
                                            <span className="font-medium text-white/75">
                                                {row.locale}
                                            </span>
                                            <span className="text-white/55 tabular-nums">
                                                {row.score === null
                                                    ? 'Not measured'
                                                    : `${row.score}%`}
                                                <span className="ml-2 text-white/35">
                                                    {row.mentions}/
                                                    {row.answered}
                                                </span>
                                            </span>
                                        </div>
                                        <div
                                            className="h-1.5 overflow-hidden rounded-full bg-white/10"
                                            role="progressbar"
                                            aria-label={`${row.locale} visibility`}
                                            aria-valuemin={0}
                                            aria-valuemax={100}
                                            aria-valuenow={
                                                row.score ?? undefined
                                            }
                                            aria-valuetext={
                                                row.score === null
                                                    ? 'Not measured'
                                                    : `${row.score} percent`
                                            }
                                        >
                                            <div
                                                className="h-full rounded-full bg-gradient-to-r from-violet-400 to-orange-300"
                                                style={{
                                                    width: `${Math.max(0, Math.min(100, row.score ?? 0))}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                        {unmeasuredLocales.length > 0 && (
                            <p className="mt-4 text-xs leading-relaxed text-white/55">
                                {unmeasuredLocales
                                    .map((row) => row.locale)
                                    .join(', ')}{' '}
                                has prompts awaiting its first answer sweep.
                            </p>
                        )}
                    </div>
                </div>

                <dl className="grid grid-cols-2 gap-y-5 border-t border-white/10 pt-5 sm:grid-cols-4 sm:divide-x sm:divide-white/10">
                    <HeroMetric
                        label="Monitored prompts"
                        value={summary.monitored_prompts}
                    />
                    <HeroMetric label="Answers read" value={summary.answered} />
                    <HeroMetric
                        label="Brand mentions"
                        value={summary.mentions}
                    />
                    <HeroMetric
                        label="Spend"
                        value={`$${summary.money_spent.toFixed(3)}`}
                    />
                </dl>
                {summary.declined > 0 && (
                    <p className="mt-4 text-xs text-white/55">
                        {summary.declined} declined{' '}
                        {summary.declined === 1 ? 'answer was' : 'answers were'}{' '}
                        excluded from the score.
                    </p>
                )}
            </div>
        </section>
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

function Bar({
    label,
    value,
    max,
}: {
    label: string;
    value: number;
    max: number;
}) {
    return (
        <div className="space-y-1">
            <div className="flex items-baseline justify-between gap-2 text-sm">
                <span className="truncate">{label}</span>
                <span className="text-muted-foreground tabular-nums">
                    {value}
                </span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full bg-gradient-to-r from-violet-500 to-orange-400"
                    style={{
                        width: `${Math.max(4, (value / Math.max(1, max)) * 100)}%`,
                    }}
                />
            </div>
        </div>
    );
}

Visibility.layout = {
    breadcrumbs: [{ title: 'Prompt analysis', href: index() }],
};
