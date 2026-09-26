import { Form, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    Check,
    FileText,
    Globe,
    PauseCircle,
    RefreshCw,
    Search,
} from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { resultDate } from '@/components/manager-results';
import { TrendChart, trendDate } from '@/components/trend-chart';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { workspacePanelClass } from '@/components/workspace-page';
import { show as showPage } from '@/routes/pages';
import { monitor, read } from '@/routes/performance';
import { edit as editProject } from '@/routes/projects';
import { changeSize, format, MetricCells, pagePath } from './search-explorer';
import { propertyHost } from './types';
import type { SiteSearch, SiteSearchTotals } from './types';

const PAGE_SIZE = 25;
const RANGES = [
    { key: '28d', label: '28 days', days: 28 },
    { key: '3m', label: '3 months', days: 91 },
    { key: '16m', label: '16 months', days: 486 },
] as const;
type RangeKey = (typeof RANGES)[number]['key'];
type ChartMetric = 'clicks' | 'impressions';
type Tab = 'queries' | 'pages';
const DAY = 86_400_000;
// Noon UTC keeps whole-day arithmetic clear of daylight-saving shifts.
const dayTime = (day: string) => Date.parse(`${day.slice(0, 10)}T12:00:00Z`);
const isoDay = (time: number) => new Date(time).toISOString().slice(0, 10);
type FilledDay = {
    day: string;
    clicks: number | null;
    impressions: number | null;
    position: number | null;
};

export function SiteOverview({
    site,
    owner,
    paused,
    projectId,
}: {
    site: SiteSearch;
    owner: boolean;
    paused: boolean;
    projectId: string | null;
}) {
    const host = propertyHost(site.property);

    return (
        <section
            id="site-search"
            className={`${workspacePanelClass} min-w-0 scroll-mt-6 p-5 sm:p-6`}
            aria-labelledby="site-search-title"
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2
                        id="site-search-title"
                        className="text-lg font-semibold"
                    >
                        Site overview
                    </h2>
                    <p className="mt-1 text-sm break-words text-muted-foreground">
                        {host
                            ? `Every page of ${host} in Google Search.`
                            : 'Every page of your website in Google Search.'}
                    </p>
                </div>
                {site.state === 'ready' && (
                    <Badge variant="outline" className="gap-1.5">
                        {site.reading ? (
                            <>
                                <Spinner
                                    className="size-3"
                                    aria-hidden="true"
                                    role={undefined}
                                    aria-label={undefined}
                                />
                                Updating from Google…
                            </>
                        ) : site.updated_at ? (
                            `Updated ${resultDate(site.updated_at)}`
                        ) : (
                            'Latest measurements'
                        )}
                    </Badge>
                )}
            </header>
            <div className="mt-5">
                <OverviewBody
                    site={site}
                    host={host}
                    owner={owner}
                    paused={paused}
                    projectId={projectId}
                />
            </div>
        </section>
    );
}

function OverviewBody({
    site,
    host,
    owner,
    paused,
    projectId,
}: {
    site: SiteSearch;
    host: string | null;
    owner: boolean;
    paused: boolean;
    projectId: string | null;
}) {
    switch (site.state) {
        case 'no_property':
            return (
                <Notice
                    icon={<Globe className="size-5" />}
                    title="Choose a Search Console property"
                    action={
                        owner && projectId ? (
                            <Button asChild>
                                <Link href={editProject(projectId)}>
                                    Choose a property
                                </Link>
                            </Button>
                        ) : null
                    }
                >
                    {owner
                        ? 'Google is connected. Pick the Search Console property for this website in project settings to see its clicks, impressions, and top searches.'
                        : 'Google is connected, but no Search Console property is chosen yet. Ask your business owner to choose one in project settings.'}
                </Notice>
            );
        case 'reading':
            return <ReadingState />;
        case 'no_data':
            return (
                <Notice
                    icon={<Search className="size-5" />}
                    title={
                        host
                            ? `Search Console has no data for ${host} yet`
                            : 'Search Console has no data for this property yet'
                    }
                >
                    Google hasn’t recorded any searches for this property. This
                    is normal for a new website or one verified in Search
                    Console recently. Data usually appears a few days after
                    Google starts showing your pages.
                    {site.property && (
                        <span className="mt-2 block text-xs break-all">
                            Property: {site.property}
                        </span>
                    )}
                </Notice>
            );
        case 'failed':
            return (
                <Notice
                    icon={<AlertTriangle className="size-5" />}
                    title="Unable to read search data from Google"
                    action={
                        owner ? (
                            <RefreshButton
                                label="Try again"
                                paused={paused}
                                reading={site.reading}
                            />
                        ) : null
                    }
                >
                    {site.reason ??
                        'Google didn’t return your search data this time.'}{' '}
                    {owner
                        ? 'Try again, or reconnect Google in project settings if this keeps happening.'
                        : 'Your business owner can try again.'}
                </Notice>
            );
        case 'paused':
            return (
                <Notice
                    icon={<PauseCircle className="size-5" />}
                    title="Search data is paused"
                >
                    {site.reason ??
                        'This project is paused, so search data isn’t being read from Google.'}
                </Notice>
            );
        case 'ready':
            return <ReadyState site={site} owner={owner} />;
        default:
            return null;
    }
}

export function RefreshButton({
    label = 'Refresh search data',
    paused,
    reading,
}: {
    label?: string;
    paused: boolean;
    reading: boolean;
}) {
    return (
        <Form {...read.form()} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <Button disabled={processing || reading || paused}>
                    <RefreshCw
                        aria-hidden="true"
                        className={`size-4 ${processing || reading ? 'animate-spin motion-reduce:animate-none' : ''}`}
                    />
                    {paused
                        ? 'Project paused'
                        : processing || reading
                          ? 'Refreshing…'
                          : label}
                </Button>
            )}
        </Form>
    );
}

function Notice({
    icon,
    title,
    action,
    children,
}: {
    icon: ReactNode;
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-5 rounded-xl border bg-muted/30 p-5">
            <div className="flex max-w-2xl min-w-0 items-start gap-4">
                <span
                    className="rounded-xl bg-muted p-3 text-muted-foreground"
                    aria-hidden="true"
                >
                    {icon}
                </span>
                <div className="min-w-0">
                    <h3 className="font-semibold break-words">{title}</h3>
                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                        {children}
                    </p>
                </div>
            </div>
            {action}
        </div>
    );
}

function ReadingState() {
    return (
        <div aria-busy="true">
            <p
                role="status"
                className="flex items-start gap-3 rounded-xl border bg-muted/30 p-4 text-sm leading-6"
            >
                <Spinner
                    className="mt-1 shrink-0"
                    aria-hidden="true"
                    role={undefined}
                    aria-label={undefined}
                />
                <span>
                    <span className="font-medium">
                        Reading your last 16 months from Google.
                    </span>{' '}
                    <span className="text-muted-foreground">
                        This usually takes under a minute. The page updates on
                        its own.
                    </span>
                </span>
            </p>
            <div className="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                {['Clicks', 'Impressions', 'CTR', 'Average position'].map(
                    (label) => (
                        <div key={label} className="rounded-xl border p-4">
                            <p className="text-sm text-muted-foreground">
                                {label}
                            </p>
                            <Skeleton className="mt-3 h-8 w-24" />
                            <Skeleton className="mt-3 h-3 w-32" />
                        </div>
                    ),
                )}
            </div>
            <Skeleton className="mt-5 h-44 w-full rounded-xl" />
            <div className="mt-5 space-y-2">
                {[0, 1, 2, 3, 4].map((row) => (
                    <Skeleton key={row} className="h-10 w-full" />
                ))}
            </div>
        </div>
    );
}

function ReadyState({ site, owner }: { site: SiteSearch; owner: boolean }) {
    const { current, previous, windows } = site;

    return (
        <div className="space-y-6">
            <div className="text-sm">
                <p className="font-medium">
                    {resultDate(windows.current.from)} –{' '}
                    {resultDate(windows.current.to)} · {windows.current.days}{' '}
                    days
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                    Compared with {resultDate(windows.previous.from)} –{' '}
                    {resultDate(windows.previous.to)}.
                </p>
            </div>
            <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile
                    label="Clicks"
                    metric="clicks"
                    current={current}
                    previous={previous}
                />
                <StatTile
                    label="Impressions"
                    metric="impressions"
                    current={current}
                    previous={previous}
                />
                <StatTile
                    label="CTR"
                    metric="ctr"
                    current={current}
                    previous={previous}
                />
                <StatTile
                    label="Average position"
                    metric="position"
                    current={current}
                    previous={previous}
                />
            </dl>
            <Trend
                daily={site.daily}
                end={windows.current.to}
                historyFrom={site.history_from}
            />
            <TopLists site={site} owner={owner} />
            <p className="text-xs leading-5 text-muted-foreground">
                CTR (click-through rate) is the share of impressions that became
                clicks. For average position, a lower number is better. Google
                hides rare searches for privacy, so query totals can be lower
                than site totals. Search dates use Pacific time.
            </p>
        </div>
    );
}

type Metric = 'clicks' | 'impressions' | 'ctr' | 'position';
const kind = (metric: Metric) =>
    metric === 'ctr' ? 'rate' : metric === 'position' ? 'position' : 'count';

function StatTile({
    label,
    metric,
    current,
    previous,
}: {
    label: string;
    metric: Metric;
    current: SiteSearchTotals | null;
    previous: SiteSearchTotals | null;
}) {
    const now = current?.[metric] ?? null;
    const before = previous?.[metric] ?? null;
    let change: ReactNode = (
        <span className="text-muted-foreground">
            No earlier period to compare
        </span>
    );

    if (now !== null && before !== null) {
        // Positive delta always means "better": for position, lower is better.
        const delta = changeSize(metric, now, before);
        const size = Math.abs(delta);
        const amount =
            metric === 'ctr'
                ? `${size.toFixed(1)} pp`
                : metric === 'position'
                  ? size.toFixed(1)
                  : size.toLocaleString();
        const share =
            (metric === 'clicks' || metric === 'impressions') && before > 0
                ? Math.round((size / before) * 100)
                : 0;
        const percent = share > 0 ? ` (${delta > 0 ? '+' : '−'}${share}%)` : '';
        const text =
            delta === 0
                ? 'No change'
                : metric === 'position'
                  ? `${delta > 0 ? 'Up' : 'Down'} ${amount}`
                  : `${delta > 0 ? '+' : '−'}${amount}${percent}`;
        const tone =
            text === 'No change'
                ? 'text-muted-foreground'
                : delta > 0
                  ? 'text-emerald-800 dark:text-emerald-300'
                  : 'text-amber-800 dark:text-amber-300';

        change = (
            <>
                <span className={`font-medium ${tone}`}>{text}</span>{' '}
                <span className="text-muted-foreground">
                    vs previous 28 days
                    {metric === 'position' && text !== 'No change'
                        ? ` (was ${format(before, 'position')})`
                        : ''}
                </span>
            </>
        );
    }

    return (
        <div className="min-w-0 rounded-xl border bg-card/60 p-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-2 text-3xl font-semibold tracking-tight tabular-nums">
                {format(now, kind(metric))}
            </dd>
            <dd className="mt-2 text-xs leading-5 tabular-nums">{change}</dd>
        </div>
    );
}

function Toggle<T extends string>({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: { value: T; label: string }[];
    value: T;
    onChange: (value: T) => void;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className="flex gap-1 rounded-lg bg-muted p-1"
        >
            {options.map((option) => (
                <button
                    type="button"
                    key={option.value}
                    aria-pressed={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={`min-h-8 rounded-md px-3 py-1.5 text-xs font-medium focus-visible:outline-2 focus-visible:outline-ring ${value === option.value ? 'bg-card shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

function Trend({
    daily,
    end,
    historyFrom,
}: {
    daily: SiteSearch['daily'];
    end: string;
    historyFrom: string | null;
}) {
    const [metric, setMetric] = useState<ChartMetric>('clicks');
    const [range, setRange] = useState<RangeKey>('28d');
    const lastStored = daily.length > 0 ? daily[daily.length - 1].day : end;
    const last = Math.max(dayTime(end), dayTime(lastStored));
    const first = Math.min(
        ...[historyFrom, daily[0]?.day]
            .filter((day): day is string => Boolean(day))
            .map(dayTime),
        last,
    );
    const span = Math.round((last - first) / DAY) + 1;
    // Offer a longer range only when the history goes beyond the shorter one.
    const ranges = RANGES.filter(
        (item, index) => index === 0 || span > RANGES[index - 1].days,
    );
    const days = (ranges.find((item) => item.key === range) ?? ranges[0]).days;
    // Google omits days without impressions, so lay out every calendar day
    // and leave the missing ones empty; the chart draws them as gaps.
    const byDay = new Map(daily.map((point) => [point.day, point]));
    const start = Math.max(first, last - (days - 1) * DAY);
    const visible: FilledDay[] = [];

    for (let time = start; time <= last; time += DAY) {
        const day = isoDay(time);
        const stored = byDay.get(day);

        visible.push({
            day,
            clicks: stored?.clicks ?? null,
            impressions: stored?.impressions ?? null,
            position: stored?.position ?? null,
        });
    }

    const unit = metric === 'clicks' ? 'clicks' : 'impressions';
    const total = visible.reduce((sum, point) => sum + (point[metric] ?? 0), 0);
    const peak = visible.reduce<FilledDay | null>(
        (best, point) =>
            point[metric] !== null &&
            (best === null || point[metric] > (best[metric] ?? 0))
                ? point
                : best,
        null,
    );
    const from = visible[0]?.day;
    const to = visible[visible.length - 1]?.day;
    const longRange = span > 120 && days > 91;

    if (daily.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="site-trend-title" className="min-w-0">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h3 id="site-trend-title" className="font-semibold">
                        Daily {unit}
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground tabular-nums">
                        <span className="font-medium text-foreground">
                            {total.toLocaleString()}
                        </span>{' '}
                        {unit}
                        {from &&
                            to &&
                            ` · ${trendDate(from, longRange)} – ${trendDate(to, longRange)}`}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Toggle
                        label="Chart measurement"
                        value={metric}
                        onChange={setMetric}
                        options={[
                            { value: 'clicks', label: 'Clicks' },
                            { value: 'impressions', label: 'Impressions' },
                        ]}
                    />
                    <Toggle
                        label="Chart date range"
                        value={
                            ranges.some((item) => item.key === range)
                                ? range
                                : '28d'
                        }
                        onChange={setRange}
                        options={ranges.map((item) => ({
                            value: item.key,
                            label: item.label,
                        }))}
                    />
                </div>
            </div>
            <div className="mt-4">
                <TrendChart
                    points={visible.map((point) => ({
                        day: point.day,
                        value: point[metric],
                    }))}
                    unit={unit}
                    label={`Daily Google ${unit} for your whole website`}
                    summary={
                        from && to && peak
                            ? `${total.toLocaleString()} ${unit} from ${trendDate(from, true)} to ${trendDate(to, true)}. Busiest day: ${trendDate(peak.day, true)} with ${(peak[metric] ?? 0).toLocaleString()} ${unit}. Days Google didn’t report are gaps. Daily numbers are in the table below the chart.`
                            : undefined
                    }
                />
            </div>
            <details className="mt-3 text-xs text-muted-foreground">
                <summary className="cursor-pointer">Daily numbers</summary>
                <div
                    className="mt-3 max-h-64 overflow-auto"
                    tabIndex={0}
                    role="region"
                    aria-label="Daily search numbers"
                >
                    <table className="w-full text-left tabular-nums">
                        <caption className="sr-only">
                            Daily Google Search measurements for your whole
                            website, newest first
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col" className="py-1">
                                    Date
                                </th>
                                <th scope="col">Clicks</th>
                                <th scope="col">Impressions</th>
                                <th scope="col">Avg. position</th>
                            </tr>
                        </thead>
                        <tbody>
                            {[...visible].reverse().map((point) => (
                                <tr key={point.day}>
                                    <th
                                        scope="row"
                                        className="py-1 font-normal"
                                    >
                                        {trendDate(point.day, true)}
                                    </th>
                                    <td>{format(point.clicks)}</td>
                                    <td>{format(point.impressions)}</td>
                                    <td>
                                        {format(point.position, 'position')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </details>
        </section>
    );
}

function TopLists({ site, owner }: { site: SiteSearch; owner: boolean }) {
    const [tab, setTab] = useState<Tab>('queries');
    const [term, setTerm] = useState('');
    const [limit, setLimit] = useState(PAGE_SIZE);
    const base = useId();
    const tabs = useRef<Record<Tab, HTMLButtonElement | null>>({
        queries: null,
        pages: null,
    });
    const needle = term.trim().toLocaleLowerCase();
    const queries = site.top_queries.filter((row) =>
        row.query.toLocaleLowerCase().includes(needle),
    );
    const pages = site.top_pages.filter((row) =>
        row.url.toLocaleLowerCase().includes(needle),
    );
    const total =
        tab === 'queries' ? site.top_queries.length : site.top_pages.length;
    const matches = tab === 'queries' ? queries.length : pages.length;
    const noun = tab === 'queries' ? 'queries' : 'pages';

    const select = (next: Tab) => {
        setTab(next);
        setTerm('');
        setLimit(PAGE_SIZE);
    };
    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            const next: Tab =
                event.key === 'Home'
                    ? 'queries'
                    : event.key === 'End'
                      ? 'pages'
                      : tab === 'queries'
                        ? 'pages'
                        : 'queries';
            select(next);
            tabs.current[next]?.focus();
        }
    };
    const tabButton = (value: Tab, icon: ReactNode, label: string) => (
        <button
            ref={(node) => {
                tabs.current[value] = node;
            }}
            type="button"
            role="tab"
            id={`${base}-${value}-tab`}
            aria-selected={tab === value}
            aria-controls={`${base}-panel`}
            tabIndex={tab === value ? 0 : -1}
            onClick={() => select(value)}
            onKeyDown={onKeyDown}
            className={`inline-flex min-h-9 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring ${tab === value ? 'bg-card shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
        >
            {icon}
            {label}
        </button>
    );

    return (
        <section aria-label="Top searches and pages" className="min-w-0">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div
                    role="tablist"
                    aria-label="Top searches and pages"
                    className="flex gap-1 rounded-xl bg-muted p-1"
                >
                    {tabButton(
                        'queries',
                        <Search className="size-4" aria-hidden="true" />,
                        'Top queries',
                    )}
                    {tabButton(
                        'pages',
                        <FileText className="size-4" aria-hidden="true" />,
                        'Top pages',
                    )}
                </div>
                <label className="w-full min-w-0 text-xs font-medium sm:w-72">
                    {tab === 'queries' ? 'Find a query' : 'Find a page'}
                    <Input
                        type="search"
                        className="mt-1.5"
                        placeholder={
                            tab === 'queries'
                                ? 'e.g. opening hours'
                                : 'e.g. /blog/'
                        }
                        value={term}
                        onChange={(event) => {
                            setTerm(event.target.value);
                            setLimit(PAGE_SIZE);
                        }}
                    />
                </label>
            </div>
            <div
                role="tabpanel"
                id={`${base}-panel`}
                aria-labelledby={`${base}-${tab}-tab`}
                className="mt-4 min-w-0 overflow-hidden rounded-xl border"
            >
                <p
                    role="status"
                    className="border-b bg-muted/20 px-4 py-2 text-xs text-muted-foreground"
                >
                    {total === 0
                        ? ''
                        : `Showing ${Math.min(limit, matches).toLocaleString()} of ${matches.toLocaleString()} ${noun}${needle ? ` matching “${term.trim()}”` : ''}`}
                </p>
                {total === 0 ? (
                    <EmptyList
                        title={
                            tab === 'queries'
                                ? 'No queries reported for this period'
                                : 'No pages reported for this period'
                        }
                        description={
                            tab === 'queries'
                                ? 'Google keeps rare searches private, so small websites sometimes see totals without individual queries.'
                                : 'Google hasn’t reported clicks or impressions for individual pages in the last 28 days.'
                        }
                    />
                ) : matches === 0 ? (
                    <EmptyList
                        title={`No ${noun} match “${term.trim()}”`}
                        description="Try a shorter or different word."
                        action={
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setTerm('')}
                            >
                                Clear search
                            </Button>
                        }
                    />
                ) : (
                    <div
                        className="overflow-x-auto"
                        tabIndex={0}
                        role="region"
                        aria-label={`Top ${noun}; scroll sideways on small screens`}
                    >
                        <table className="w-full min-w-[720px] text-sm">
                            <caption className="sr-only">
                                Top {noun} on Google for the current 28 days,
                                with changes from the previous 28 days
                            </caption>
                            <thead>
                                <tr className="border-b bg-muted/20 text-left text-xs text-muted-foreground">
                                    <th scope="col" className="px-4 py-3">
                                        {tab === 'queries'
                                            ? 'Search query'
                                            : 'Website page'}
                                    </th>
                                    <th scope="col" className="px-4 py-3">
                                        Clicks
                                    </th>
                                    <th scope="col" className="px-4 py-3">
                                        Impressions
                                    </th>
                                    <th scope="col" className="px-4 py-3">
                                        CTR
                                    </th>
                                    <th scope="col" className="px-4 py-3">
                                        Avg. position
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {tab === 'queries'
                                    ? queries.slice(0, limit).map((row) => (
                                          <tr
                                              key={row.query}
                                              className="border-b last:border-0 hover:bg-muted/20"
                                          >
                                              <th
                                                  scope="row"
                                                  className="max-w-sm px-4 py-3 text-left align-top font-medium break-words"
                                              >
                                                  {row.query}
                                              </th>
                                              <MetricCells
                                                  current={row}
                                                  previous={row.previous}
                                                  comparable={
                                                      row.previous !== null
                                                  }
                                              />
                                          </tr>
                                      ))
                                    : pages.slice(0, limit).map((row) => (
                                          <tr
                                              key={row.url}
                                              className="border-b last:border-0 hover:bg-muted/20"
                                          >
                                              <th
                                                  scope="row"
                                                  className="max-w-md px-4 py-3 text-left align-top font-normal"
                                              >
                                                  <PageCell
                                                      row={row}
                                                      owner={owner}
                                                  />
                                              </th>
                                              <MetricCells
                                                  current={row}
                                                  previous={row.previous}
                                                  comparable={
                                                      row.previous !== null
                                                  }
                                              />
                                          </tr>
                                      ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {matches > limit && (
                    <div className="border-t p-3 text-center">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setLimit(limit + PAGE_SIZE)}
                        >
                            Show {Math.min(PAGE_SIZE, matches - limit)} more{' '}
                            {noun}
                        </Button>
                    </div>
                )}
            </div>
        </section>
    );
}

function PageCell({
    row,
    owner,
}: {
    row: SiteSearch['top_pages'][number];
    owner: boolean;
}) {
    const path = pagePath(row.url);
    const errorId = useId();

    return (
        <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
            <a
                href={row.url}
                target="_blank"
                rel="noreferrer"
                className="flex min-w-0 items-start gap-1 text-sm leading-6 font-medium break-all underline-offset-4 hover:underline"
            >
                {path}
                <ArrowUpRight
                    className="mt-1 size-3 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span className="sr-only"> (opens in a new tab)</span>
            </a>
            {row.tracked ? (
                row.page_id ? (
                    <Badge variant="secondary" asChild className="gap-1">
                        <Link href={showPage(row.page_id)}>
                            <Check className="size-3" aria-hidden="true" />
                            Monitored
                            <span className="sr-only">: open {path}</span>
                        </Link>
                    </Badge>
                ) : (
                    <Badge variant="secondary" className="gap-1">
                        <Check className="size-3" aria-hidden="true" />
                        Monitored
                    </Badge>
                )
            ) : (
                owner && (
                    <Form
                        {...monitor.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <div className="flex max-w-60 flex-col items-end gap-1">
                                <input
                                    type="hidden"
                                    name="url"
                                    value={row.url}
                                />
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-8"
                                    disabled={processing}
                                    aria-describedby={errorId}
                                >
                                    {processing
                                        ? 'Adding…'
                                        : errors.url
                                          ? 'Try again'
                                          : 'Monitor'}
                                    <span className="sr-only"> {path}</span>
                                </Button>
                                <p
                                    id={errorId}
                                    aria-live="polite"
                                    className="text-right text-xs leading-5 text-destructive"
                                >
                                    {errors.url}
                                </p>
                            </div>
                        )}
                    </Form>
                )
            )}
        </div>
    );
}

function EmptyList({
    title,
    description,
    action,
}: {
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <div className="px-6 py-10 text-center">
            <Search
                className="mx-auto mb-3 size-6 text-muted-foreground"
                aria-hidden="true"
            />
            <h3 className="font-medium break-words">{title}</h3>
            <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-muted-foreground">
                {description}
            </p>
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
