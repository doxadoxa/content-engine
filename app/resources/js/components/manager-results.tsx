import { Link } from '@inertiajs/react';
import { ArrowUpRight, Loader2, Search, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { TrendChart } from '@/components/trend-chart';
import { Button } from '@/components/ui/button';
import { workspacePanelClass } from '@/components/workspace-page';

export type ResultsSummary = {
    published_articles: number;
    search: {
        clicks: number | null;
        impressions: number | null;
        previous_clicks: number | null;
        previous_impressions: number | null;
        observed_pages: number;
        tracked_pages: number;
        from: string;
        to: string;
        stale: boolean;
        connected: boolean;
        status: string;
        updated_at: string | null;
        /** `site` = the whole Search Console property; else monitored pages only. */
        scope: 'site' | 'tracked_pages';
        state:
            | 'not_connected'
            | 'no_property'
            | 'reading'
            | 'ready'
            | 'no_data'
            | 'failed'
            | 'paused';
        /** Why search data is failing or paused, when the server knows. */
        reason?: string | null;
        daily: {
            day: string;
            clicks: number | null;
            impressions: number | null;
            observed_pages: number;
        }[];
    };
    ai: {
        status: string;
        run_id: string | null;
        sampled_at: string | null;
        answers: number;
        expected: number;
        mentions: number | null;
        citations: number | null;
        score: number | null;
        providers: {
            platform: string;
            label: string;
            answers: number;
            expected: number;
            mentions: number | null;
            score: number | null;
            sampled_at: string | null;
        }[];
        has_questions: boolean;
        latest_attempt: { status: string; created_at: string } | null;
    };
    earlier_ai: EarlierVisibility | null;
    purchases: {
        count: number | null;
        status: string;
        connected: boolean;
        paused: boolean;
        from: string;
        to: string;
        updated_at: string | null;
    };
};

export type EarlierVisibility = {
    score: number | null;
    answers: number;
    mentions: number;
    sampled_at: string | null;
    providers: {
        platform: string;
        label: string;
        answered: number;
        mentions: number;
        score: number | null;
        last_asked_on: string | null;
        stale: boolean;
    }[];
};

export const resultDate = (date: string) =>
    new Date(date.length === 10 ? `${date}T12:00:00` : date).toLocaleDateString(
        undefined,
        { month: 'short', day: 'numeric' },
    );
const number = (value: number | null) =>
    value === null ? '—' : value.toLocaleString();
const percentage = (value: number | null) =>
    value === null
        ? '—'
        : `${value.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;

function visibility(results: ResultsSummary) {
    const earlier = results.ai.answers === 0 ? results.earlier_ai : null;

    return {
        earlier: earlier !== null,
        score: earlier?.score ?? results.ai.score,
        answers: earlier?.answers ?? results.ai.answers,
        mentions: earlier?.mentions ?? results.ai.mentions,
        sampled_at: earlier?.sampled_at ?? results.ai.sampled_at,
        href: earlier
            ? '/visibility#earlier-checks'
            : results.ai.run_id
              ? `/visibility/runs/${results.ai.run_id}`
              : '/visibility',
        providers: earlier
            ? earlier.providers.map((provider) => ({
                  ...provider,
                  answers: provider.answered,
                  expected: provider.answered,
                  sampled_at: provider.last_asked_on,
              }))
            : results.ai.providers,
    };
}

type Search = ResultsSummary['search'];

/** What a card says while it has no number: a short reason, maybe busy. */
type Status = { label: string; busy?: boolean };

const siteWide = (search: Search) => search.scope === 'site';

/** One line on where the search numbers come from, or why there are none. */
function searchNote(search: Search) {
    if (siteWide(search)) {
        switch (search.state) {
            case 'not_connected':
                return 'Connect Google Search Console';
            case 'no_property':
                return 'Choose a Search Console property';
            case 'reading':
                return 'Reading your search data from Google…';
            case 'no_data':
                return 'No search data in Google yet';
            case 'failed':
                return 'Unable to read search data';
            case 'paused':
                return 'Search data paused';
            default:
                return `Whole website${search.stale ? ' · update needed' : ''}`;
        }
    }

    return search.observed_pages > 0
        ? `${search.observed_pages} of ${search.tracked_pages} monitored pages${search.stale ? ' · update needed' : ''}`
        : search.connected
          ? 'Waiting for search measurements'
          : 'Connect Google Search Console';
}

const searchHref = (search: Search) =>
    siteWide(search)
        ? '/performance#site-search'
        : '/performance#search-results';

/**
 * What a search card says in place of a number it does not have.
 *
 * A bare dash was the answer to every one of these, and a customer in their
 * first hour read a row of them as a product that had not started. The card
 * already knows which of "not connected", "reading" and "nothing recorded" it
 * is, so it says that instead.
 */
function searchStatus(search: Search): Status {
    if (!siteWide(search)) {
        return search.connected
            ? { label: 'No data yet' }
            : { label: 'Not connected' };
    }

    switch (search.state) {
        case 'not_connected':
            return { label: 'Not connected' };
        case 'no_property':
            // Google is connected; what is missing is which property to read.
            return { label: 'Choose property' };
        case 'reading':
            return { label: 'Reading…', busy: true };
        case 'failed':
            return { label: 'Unavailable' };
        case 'paused':
            return { label: 'Paused' };
        default:
            return { label: 'No data yet' };
    }
}

export function ManagerResults({
    results,
    checking = false,
}: {
    results: ResultsSummary;
    /** An AI visibility check is running now, so "not checked" would be wrong. */
    checking?: boolean;
}) {
    const { search, purchases } = results;
    const ai = visibility(results);
    const note = searchNote(search);
    const searchPending = searchStatus(search);

    return (
        <section
            className="grid grid-cols-2 gap-3 xl:grid-cols-4"
            aria-label="Visibility, traffic and purchases"
        >
            <ResultCard
                title="AI visibility"
                value={ai.score === null ? null : percentage(ai.score)}
                pending={
                    checking
                        ? { label: 'Checking now', busy: true }
                        : { label: 'Not checked yet' }
                }
                note={
                    ai.answers > 0
                        ? `Mentioned in ${ai.mentions} of ${ai.answers} answers`
                        : checking
                          ? 'Asking the assistants about your business'
                          : 'See whether AI names your business'
                }
                caption={
                    ai.sampled_at
                        ? `${ai.earlier ? 'Earlier checks' : 'Latest check'} · ${resultDate(ai.sampled_at)}`
                        : 'No checks recorded yet'
                }
                href={ai.href}
                accent
            />
            <ResultCard
                title="Clicks from Google"
                value={search.clicks === null ? null : number(search.clicks)}
                pending={searchPending}
                change={
                    search.clicks !== null && search.previous_clicks !== null
                        ? search.clicks - search.previous_clicks
                        : null
                }
                note={note}
                caption={`${resultDate(search.from)} – ${resultDate(search.to)}`}
                href={searchHref(search)}
            />
            <ResultCard
                title="Search impressions"
                value={
                    search.impressions === null
                        ? null
                        : number(search.impressions)
                }
                pending={searchPending}
                change={
                    search.impressions !== null &&
                    search.previous_impressions !== null
                        ? search.impressions - search.previous_impressions
                        : null
                }
                note={note}
                caption={
                    siteWide(search)
                        ? 'Times your website appeared in Google'
                        : 'Times your pages appeared in Google'
                }
                href={searchHref(search)}
            />
            <ResultCard
                title="Recorded purchases"
                value={
                    purchases.count === null ? null : number(purchases.count)
                }
                pending={
                    purchases.connected
                        ? { label: 'Waiting' }
                        : { label: 'Not connected' }
                }
                note={
                    purchases.count !== null
                        ? purchases.paused
                            ? 'Tracking paused · saved records'
                            : purchases.status === 'unverified'
                              ? 'Received records · not verified yet'
                              : 'Completed sales · includes later refunds'
                        : purchases.connected
                          ? 'Waiting for purchase records'
                          : 'Purchase tracking not connected'
                }
                caption={`${resultDate(purchases.from)} – ${resultDate(purchases.to)} · UTC`}
                href="/purchases"
            />
        </section>
    );
}

export function DashboardCharts({
    results,
    projectId,
    checking = false,
}: {
    results: ResultsSummary;
    projectId: string;
    checking?: boolean;
}) {
    return (
        <div className="grid items-start gap-5 xl:grid-cols-2">
            <AiVisibilityPanel results={results} checking={checking} />
            <SearchTrend search={results.search} projectId={projectId} />
        </div>
    );
}

export function AiVisibilityPanel({
    results,
    checking = false,
}: {
    results: ResultsSummary;
    /** A check is running: unanswered rows are being asked, not empty. */
    checking?: boolean;
}) {
    const ai = visibility(results);

    return (
        <section
            className="flex min-w-0 flex-col rounded-3xl bg-[#17352f] p-6 text-[#f3ecdd] sm:p-7"
            aria-label="AI visibility by service"
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 text-lg font-semibold">
                        <Sparkles className="size-5 text-[#f3cf6a]" />
                        AI visibility
                    </h2>
                    <p className="mt-1 text-sm text-[#f3ecdd]/75">
                        Where your business gets mentioned.
                    </p>
                </div>
                <Link
                    href={ai.href}
                    className="inline-flex items-center gap-1 text-xs font-medium underline underline-offset-4"
                >
                    View report <ArrowUpRight className="size-3.5" />
                </Link>
            </header>
            <p className="mt-5 text-sm text-[#f3ecdd]/80">
                {ai.answers > 0
                    ? `${ai.mentions} mentions in ${ai.answers} answers`
                    : checking
                      ? 'Asking the assistants now. Answers appear here as they arrive.'
                      : 'Your first check will appear here'}
                {ai.sampled_at && (
                    <span className="ml-2 text-xs">
                        · {ai.earlier ? 'Earlier checks' : 'Latest check'} ·{' '}
                        {resultDate(ai.sampled_at)}
                    </span>
                )}
            </p>
            <div className="mt-4 space-y-4">
                {ai.providers.map((provider) => (
                    <div key={provider.platform}>
                        <div className="mb-2 flex items-center justify-between gap-3 text-sm">
                            <span>{provider.label}</span>
                            <span className="shrink-0 tabular-nums">
                                {provider.score !== null ? (
                                    <>
                                        {percentage(provider.score)}{' '}
                                        <span className="ml-1 text-xs text-[#f3ecdd]/65">
                                            · {provider.mentions}/
                                            {provider.answers}
                                        </span>
                                    </>
                                ) : checking ? (
                                    <span className="inline-flex items-center gap-1.5 text-xs text-[#f3ecdd]/80">
                                        <Loader2
                                            className="size-3 animate-spin motion-reduce:animate-none"
                                            aria-hidden="true"
                                        />
                                        Asking…
                                    </span>
                                ) : (
                                    <span className="text-xs text-[#f3ecdd]/65">
                                        No answers yet
                                    </span>
                                )}
                            </span>
                        </div>
                        <div
                            className="h-1.5 overflow-hidden rounded-full bg-white/10"
                            aria-hidden="true"
                        >
                            {provider.score === null && checking ? (
                                <div className="h-full w-full rounded-full bg-[#f3cf6a]/25 motion-safe:animate-pulse" />
                            ) : (
                                <div
                                    className="h-full rounded-full bg-[#f3cf6a]"
                                    style={{ width: `${provider.score ?? 0}%` }}
                                />
                            )}
                        </div>
                        {provider.sampled_at &&
                            ai.sampled_at &&
                            provider.sampled_at.slice(0, 10) !==
                                ai.sampled_at.slice(0, 10) && (
                                <p className="mt-1 text-xs text-[#f3ecdd]/65">
                                    Checked {resultDate(provider.sampled_at)}
                                </p>
                            )}
                    </div>
                ))}
            </div>
            <div className="mt-6 border-t border-white/15 pt-4 text-xs leading-5 text-[#f3ecdd]/75">
                {ai.earlier ? (
                    <p>
                        Saved excerpt checks, shown separately from the newer
                        full-answer reports.
                    </p>
                ) : ai.answers > 0 ? (
                    <p>
                        {results.ai.citations} of {ai.answers} answers linked to
                        your website.
                        {results.ai.answers < results.ai.expected &&
                            ` ${results.ai.expected - results.ai.answers} planned answers are missing.`}
                    </p>
                ) : (
                    <Link
                        href="/visibility"
                        className="font-medium text-[#f3cf6a] underline underline-offset-4"
                    >
                        {results.ai.has_questions
                            ? 'Open your AI checks'
                            : 'Set up the questions your customers ask'}{' '}
                        →
                    </Link>
                )}
                {results.ai.latest_attempt && (
                    <p className="mt-1">
                        A newer check has no discovery answers yet. Showing the
                        last recorded results.
                    </p>
                )}
                <p className="mt-1">
                    Visibility = share of sampled answers that mention you.
                </p>
            </div>
        </section>
    );
}

function SearchTrend({
    search,
    projectId,
}: {
    search: Search;
    projectId: string;
}) {
    const [metric, setMetric] = useState<'clicks' | 'impressions'>('clicks');
    const site = siteWide(search);
    const hasData =
        (!site || search.state === 'ready') &&
        search.daily.some((day) => day[metric] !== null);
    const empty = emptyTrend(search, projectId);

    return (
        <section
            className={`${workspacePanelClass} flex min-w-0 flex-col p-6 sm:p-7 ${!hasData ? 'self-start' : ''}`}
            aria-label="Google search performance"
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold">
                        Traffic from Google
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {resultDate(search.from)} – {resultDate(search.to)} · 28
                        days
                    </p>
                </div>
                <Link
                    href={searchHref(search)}
                    className="inline-flex items-center gap-1 text-xs font-medium underline underline-offset-4"
                >
                    Explore search <ArrowUpRight className="size-3.5" />
                </Link>
            </header>
            {hasData ? (
                <>
                    <div
                        className="mt-5 flex gap-1 self-start rounded-lg bg-muted p-1"
                        role="group"
                        aria-label="Search chart metric"
                    >
                        {(['clicks', 'impressions'] as const).map((item) => (
                            <button
                                type="button"
                                key={item}
                                aria-pressed={metric === item}
                                onClick={() => setMetric(item)}
                                className={`rounded-md px-3 py-1.5 text-xs font-medium ${metric === item ? 'bg-card shadow-sm' : 'text-muted-foreground'}`}
                            >
                                {item === 'clicks' ? 'Clicks' : 'Impressions'}
                            </button>
                        ))}
                    </div>
                    <p className="mt-4 text-3xl font-semibold tabular-nums">
                        {number(search[metric])}
                        <span className="ml-2 text-sm font-normal text-muted-foreground">
                            {metric}
                        </span>
                    </p>
                    <div className="mt-3 flex-1">
                        <TrendChart
                            points={search.daily.map((day) => ({
                                day: day.day,
                                value: day[metric],
                            }))}
                            unit={metric}
                            label={`Daily Google ${metric}${site ? ' for your whole website' : ' for monitored pages'}`}
                            summary="Missing days appear as gaps. Full values are in the Daily numbers table below."
                        />
                    </div>
                    <p className="mt-4 text-xs leading-5 text-muted-foreground">
                        {site
                            ? 'Every page of your website in Google Search.'
                            : `${search.observed_pages} of ${search.tracked_pages} monitored pages. Missing days are gaps.`}
                        {search.stale &&
                            ' These saved measurements need an update.'}
                    </p>
                    <details className="mt-2 text-xs text-muted-foreground">
                        <summary className="cursor-pointer">
                            Daily numbers
                        </summary>
                        <div className="mt-3 max-h-52 overflow-auto">
                            <table className="w-full text-left tabular-nums">
                                <caption className="sr-only">
                                    {site
                                        ? 'Daily search measurements for your whole website'
                                        : 'Daily search measurements for monitored pages'}
                                </caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Date</th>
                                        <th scope="col">Clicks</th>
                                        <th scope="col">Impressions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {search.daily.map((day) => (
                                        <tr key={day.day}>
                                            <th
                                                scope="row"
                                                className="py-1 font-normal"
                                            >
                                                {resultDate(day.day)}
                                            </th>
                                            <td>{number(day.clicks)}</td>
                                            <td>{number(day.impressions)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </details>
                </>
            ) : (
                <div
                    className="mt-5 flex flex-wrap items-center gap-3 rounded-xl border bg-muted/40 p-4"
                    role={empty.busy ? 'status' : undefined}
                >
                    {empty.busy ? (
                        <Loader2
                            className="size-5 shrink-0 animate-spin text-muted-foreground motion-reduce:animate-none"
                            aria-hidden="true"
                        />
                    ) : (
                        <Search
                            className="size-5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    )}
                    <div className="min-w-0 flex-1">
                        <h3 className="text-sm font-medium">{empty.title}</h3>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            {empty.body}
                        </p>
                    </div>
                    {empty.action && (
                        <Button asChild size="sm" variant="outline">
                            <Link href={empty.action.href}>
                                {empty.action.label}
                            </Link>
                        </Button>
                    )}
                </div>
            )}
            <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">
                Google data has a reporting delay.
                {search.updated_at &&
                    ` Last updated ${resultDate(search.updated_at)}.`}
            </p>
        </section>
    );
}

/** What the trend card says while there is no chart to draw. */
function emptyTrend(
    search: Search,
    projectId: string,
): {
    title: string;
    body: string;
    busy?: boolean;
    action?: { href: string; label: string };
} {
    const settings = `/projects/${projectId}/edit`;
    const performance = { href: '/performance', label: 'Open performance' };

    const notConnected = {
        title: 'Google Search Console is not connected',
        body: 'Connect it to see clicks, impressions, and your traffic trend.',
        action: { href: settings, label: 'Connect Google' },
    };

    // Whole-site data carries its own state; `connected` is false until a
    // property is chosen, so it must not decide anything here.
    if (!siteWide(search)) {
        return search.connected
            ? {
                  title: 'Waiting for Google measurements',
                  body: 'Your traffic trend will appear after measurements are saved.',
                  action: performance,
              }
            : notConnected;
    }

    switch (search.state) {
        case 'not_connected':
            return notConnected;
        case 'no_property':
            return {
                title: 'Choose a Search Console property',
                body: 'Google is connected. Pick the property for this website in project settings to see its search traffic.',
                action: { href: settings, label: 'Choose a property' },
            };
        case 'reading':
            return {
                title: 'Reading your search data from Google…',
                body: 'Your traffic trend usually appears within a minute.',
                busy: true,
            };
        case 'no_data':
            return {
                title: 'No search data in Google yet',
                body: 'Search Console hasn’t recorded any searches for this website. This is normal for a new or recently verified site.',
                action: performance,
            };
        case 'failed':
            return {
                title: 'Unable to read search data',
                body: `${search.reason ?? 'Google didn’t return your search data.'} Open Search performance to try again.`,
                action: performance,
            };
        case 'paused':
            return {
                title: 'Search data is paused',
                body:
                    search.reason ??
                    'This project is paused, so search data isn’t being read from Google.',
            };
        default:
            return {
                title: 'No searches recorded in the last 28 days',
                body: 'Your traffic trend will appear once Google records clicks or impressions.',
                action: performance,
            };
    }
}

function ResultCard({
    title,
    value,
    pending,
    change,
    note,
    caption,
    href,
    accent = false,
}: {
    title: string;
    /** Null while unmeasured; `pending` is shown in its place. */
    value: string | null;
    pending: Status;
    change?: number | null;
    note: string;
    caption: string;
    href: string;
    accent?: boolean;
}) {
    return (
        <Link
            href={href}
            className={`${workspacePanelClass} block min-w-0 p-4 transition-colors hover:bg-muted/30 sm:p-5 ${accent ? 'border-primary/25' : ''}`}
        >
            <div className="flex items-center justify-between gap-2 text-sm font-medium">
                {title}
                <ArrowUpRight
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            </div>
            {value !== null ? (
                <p className="mt-4 text-4xl font-semibold tracking-tight tabular-nums">
                    {value}
                </p>
            ) : (
                // The same height as a number, so a row of cards half measured
                // and half not still lines up.
                <p className="mt-4 flex h-10 items-center gap-2 text-base font-medium text-muted-foreground">
                    {pending.busy && (
                        <Loader2
                            className="size-4 shrink-0 animate-spin motion-reduce:animate-none"
                            aria-hidden="true"
                        />
                    )}
                    {pending.label}
                </p>
            )}
            <p className="mt-3 text-xs leading-5 text-muted-foreground">
                {note}
            </p>
            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                {change !== null && change !== undefined
                    ? `${change > 0 ? '+' : ''}${change.toLocaleString()} vs previous 28 days`
                    : caption}
            </p>
        </Link>
    );
}
