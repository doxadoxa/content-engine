import { Link } from '@inertiajs/react';
import { ArrowUpRight, Search, Sparkles } from 'lucide-react';
import { useState } from 'react';
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

export function ManagerResults({ results }: { results: ResultsSummary }) {
    const { search, purchases } = results;
    const ai = visibility(results);
    const searchNote =
        search.observed_pages > 0
            ? `${search.observed_pages} of ${search.tracked_pages} monitored pages${search.stale ? ' · update needed' : ''}`
            : search.connected
              ? 'Waiting for search measurements'
              : 'Connect Google Search Console';

    return (
        <section
            className="grid grid-cols-2 gap-3 xl:grid-cols-4"
            aria-label="Visibility, traffic and purchases"
        >
            <ResultCard
                title="AI visibility"
                value={percentage(ai.score)}
                note={
                    ai.answers > 0
                        ? `Mentioned in ${ai.mentions} of ${ai.answers} answers`
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
                value={number(search.clicks)}
                change={
                    search.clicks !== null && search.previous_clicks !== null
                        ? search.clicks - search.previous_clicks
                        : null
                }
                note={searchNote}
                caption={`${resultDate(search.from)} – ${resultDate(search.to)}`}
                href="/performance#search-results"
            />
            <ResultCard
                title="Search impressions"
                value={number(search.impressions)}
                change={
                    search.impressions !== null &&
                    search.previous_impressions !== null
                        ? search.impressions - search.previous_impressions
                        : null
                }
                note={searchNote}
                caption="Times your pages appeared in Google"
                href="/performance#search-results"
            />
            <ResultCard
                title="Recorded purchases"
                value={number(purchases.count)}
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
}: {
    results: ResultsSummary;
    projectId: string;
}) {
    return (
        <div className="grid items-start gap-5 xl:grid-cols-2">
            <AiVisibilityPanel results={results} />
            <SearchTrend search={results.search} projectId={projectId} />
        </div>
    );
}

export function AiVisibilityPanel({ results }: { results: ResultsSummary }) {
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
                            <div
                                className="h-full rounded-full bg-[#f3cf6a]"
                                style={{ width: `${provider.score ?? 0}%` }}
                            />
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
    search: ResultsSummary['search'];
    projectId: string;
}) {
    const [metric, setMetric] = useState<'clicks' | 'impressions'>('clicks');
    const hasData = search.daily.some((day) => day[metric] !== null);
    const max = Math.max(1, ...search.daily.map((day) => day[metric] ?? 0));
    const y = (value: number) => 154 - (value / max) * 130;
    const x = (index: number) =>
        15 + (index / Math.max(1, search.daily.length - 1)) * 610;
    const path = search.daily
        .map((day, index) => {
            const value = day[metric];

            if (value === null) {
                return '';
            }

            const command =
                index === 0 || search.daily[index - 1][metric] === null
                    ? 'M'
                    : 'L';

            return `${command} ${x(index)} ${y(value)}`;
        })
        .join(' ');

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
                    href="/performance#search-results"
                    className="inline-flex items-center gap-1 text-xs font-medium underline underline-offset-4"
                >
                    Explore search <ArrowUpRight className="size-3.5" />
                </Link>
            </header>
            {hasData ? (
                <>
                    <div
                        className="mt-5 flex gap-1 self-start rounded-lg bg-muted p-1"
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
                        <div className="text-right text-xs text-muted-foreground">
                            {max.toLocaleString()}
                        </div>
                        <svg
                            viewBox="0 0 640 175"
                            className="w-full"
                            role="img"
                            aria-label={`Daily Google ${metric}. Missing days appear as gaps. Full values are in the table below.`}
                        >
                            {[24, 89, 154].map((height) => (
                                <line
                                    key={height}
                                    x1="15"
                                    x2="625"
                                    y1={height}
                                    y2={height}
                                    stroke="currentColor"
                                    className="text-border"
                                    strokeDasharray="4 5"
                                />
                            ))}
                            <path
                                d={path}
                                fill="none"
                                stroke="currentColor"
                                className="text-primary"
                                strokeWidth="3"
                                strokeLinejoin="round"
                            />
                            {search.daily.map(
                                (day, index) =>
                                    day[metric] !== null && (
                                        <circle
                                            key={day.day}
                                            cx={x(index)}
                                            cy={y(day[metric]!)}
                                            r="3"
                                            fill="currentColor"
                                            className="text-primary"
                                        >
                                            <title>
                                                {resultDate(day.day)}:{' '}
                                                {day[metric]} {metric}
                                            </title>
                                        </circle>
                                    ),
                            )}
                        </svg>
                        <div className="flex justify-between text-xs text-muted-foreground">
                            <span>{resultDate(search.from)}</span>
                            <span>{resultDate(search.to)}</span>
                        </div>
                    </div>
                    <p className="mt-4 text-xs leading-5 text-muted-foreground">
                        {search.observed_pages} of {search.tracked_pages}{' '}
                        monitored pages. Missing days are gaps.
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
                                    Daily search measurements for monitored
                                    pages
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
                <div className="mt-5 flex flex-wrap items-center gap-3 rounded-xl border bg-muted/40 p-4">
                    <Search
                        className="size-5 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div className="min-w-0 flex-1">
                        <h3 className="text-sm font-medium">
                            {search.connected
                                ? 'Waiting for Google measurements'
                                : 'Google Search Console is not connected'}
                        </h3>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            {search.connected
                                ? 'Your traffic trend will appear after measurements are saved.'
                                : 'Connect it to see clicks, impressions, and your traffic trend.'}
                        </p>
                    </div>
                    <Button asChild size="sm" variant="outline">
                        <Link
                            href={
                                search.connected
                                    ? '/performance'
                                    : `/projects/${projectId}/edit`
                            }
                        >
                            {search.connected
                                ? 'Open performance'
                                : 'Connect Google'}
                        </Link>
                    </Button>
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

function ResultCard({
    title,
    value,
    change,
    note,
    caption,
    href,
    accent = false,
}: {
    title: string;
    value: string;
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
            <p className="mt-4 text-4xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
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
