import { Head, Link, usePage, usePoll } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect } from 'react';
import { resultDate } from '@/components/manager-results';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { index as feedbackIndex } from '@/routes/feedback';
import { connect as connectGoogle } from '@/routes/google';
import { index as pagesIndex } from '@/routes/pages';
import { edit as editProject } from '@/routes/projects';
import { ChangeFollowups } from './change-followups';
import { SearchExplorer } from './search-explorer';
import { RefreshButton, SiteOverview } from './site-overview';
import { propertyHost } from './types';
import type { AnalyticsTotals, Report, SiteSearch, Source } from './types';

const labels: Record<Source['status'], string> = {
    not_read: 'Not read yet',
    reading: 'Reading',
    complete: 'Read finished',
    partial: 'Incomplete read',
    unavailable: 'Not connected or unavailable',
    incompatible: 'Report not supported',
    failed: 'Read failed',
};
const value = (number: number | null) =>
    number === null ? 'Not observed' : number.toLocaleString();
const when = (time: string | null) =>
    time ? new Date(time).toLocaleString() : 'Never';

export default function Performance({
    report,
    search_connected: connected,
    search_data_mode: dataMode,
    site_search: site,
}: {
    report: Report;
    search_connected: boolean;
    search_data_mode: 'current' | 'saved' | 'none';
    site_search: SiteSearch;
}) {
    const { auth } = usePage().props;
    const project = auth.project;
    const owner = project?.role === 'owner';
    const paused = project?.status === 'paused';
    const reading = Object.values(report.sources).some(
        (source) => source.status === 'reading',
    );
    const hasSearchData = report.pages.some(
        (page) =>
            page.current.search.impressions !== null ||
            page.previous.search.impressions !== null ||
            page.current.queries.length > 0 ||
            page.previous.queries.length > 0,
    );
    const currentSearchData = report.pages.some(
        (page) =>
            page.current.search.impressions !== null ||
            page.current.queries.length > 0,
    );
    const currentSearchActivity = report.pages.some(
        (page) =>
            (page.current.search.impressions !== null &&
                page.current.search.impressions > 0) ||
            page.current.queries.some(
                (query) => query.impressions !== null && query.impressions > 0,
            ),
    );
    const notConnected = site.state === 'not_connected';
    const compactExplorer = notConnected && !hasSearchData;
    const stale =
        report.sources.gsc_pages.stale || report.sources.gsc_queries.stale;
    const siteReading =
        !['paused', 'failed'].includes(site.state) &&
        (site.state === 'reading' || site.reading);
    const hasProperty = !['not_connected', 'no_property', 'paused'].includes(
        site.state,
    );
    const host = propertyHost(site.property);
    const pollProps = [
        'report',
        'search_connected',
        'search_data_mode',
        'site_search',
    ];
    usePoll(30000, { only: pollProps });
    // While Google is being read, check back every few seconds so the first
    // report appears soon after it lands instead of up to 30 seconds later.
    const fastPoll = usePoll(5000, { only: pollProps }, { autoStart: false });
    useEffect(() => {
        if (siteReading) {
            fastPoll.start();
        } else {
            fastPoll.stop();
        }

        return () => fastPoll.stop();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [siteReading]);

    return (
        <>
            <Head title="Search performance" />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Google Search"
                    title="Search performance"
                    context={
                        host
                            ? `${host} · ${monitoredLabel(report.pages.length)}`
                            : monitoredLabel(report.pages.length)
                    }
                    description="See the searches that bring people to your website and the pages they find."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={pagesIndex()}>Manage pages</Link>
                            </Button>
                            {owner &&
                                connected &&
                                site.state !== 'paused' &&
                                (hasProperty || report.pages.length > 0) && (
                                    <RefreshButton
                                        paused={paused}
                                        reading={reading || siteReading}
                                    />
                                )}
                        </>
                    }
                />
                {notConnected && (
                    <section
                        className={`${workspacePanelClass} flex flex-wrap items-center justify-between gap-5 p-6`}
                        aria-label="Search Console connection"
                    >
                        <div className="flex max-w-2xl items-start gap-4">
                            <span className="rounded-xl bg-muted p-3">
                                <Search className="size-5" />
                            </span>
                            <div>
                                <h2 className="font-semibold">
                                    Connect Google Search Console
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                    {hasSearchData
                                        ? 'Saved measurements are retained below with their observation dates. Connect Search Console to update them.'
                                        : 'See the exact searches customers use, which pages they discover, and how your positions change.'}
                                </p>
                            </div>
                        </div>
                        {owner && project ? (
                            <Button asChild>
                                <Link href={editProject(project.id)}>
                                    Connect Search Console
                                </Link>
                            </Button>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Ask your business owner to connect Search
                                Console.
                            </p>
                        )}
                        {compactExplorer && (
                            <MonitoredPages pages={report.pages} />
                        )}
                    </section>
                )}
                {!notConnected && (
                    <SiteOverview
                        site={site}
                        owner={owner}
                        paused={paused}
                        projectId={project?.id ?? null}
                    />
                )}
                <section
                    aria-labelledby="monitored-pages-title"
                    className="mt-4 flex min-w-0 flex-col gap-5 sm:gap-6"
                >
                    <div>
                        <h2
                            id="monitored-pages-title"
                            className="text-lg font-semibold"
                        >
                            Monitored pages
                        </h2>
                        <p className="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Pages you monitor get a before-and-after comparison
                            each time you improve them.
                        </p>
                    </div>
                    {report.pages.length === 0 ? (
                        <p className="rounded-xl border bg-card/60 p-4 text-sm leading-6 text-muted-foreground">
                            No pages monitored yet.{' '}
                            {owner && site.state === 'ready'
                                ? 'Choose Monitor next to a page in Top pages above, or add pages in '
                                : 'Add pages in '}
                            <Link href={pagesIndex()} className="underline">
                                Manage pages
                            </Link>
                            .
                        </p>
                    ) : (
                        <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <div>
                                <p className="font-medium">
                                    {resultDate(report.windows.current.from)} –{' '}
                                    {resultDate(report.windows.current.to)} ·{' '}
                                    {report.windows.current.days} days
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Compared with{' '}
                                    {resultDate(report.windows.previous.from)} –{' '}
                                    {resultDate(report.windows.previous.to)}.
                                    Google’s latest{' '}
                                    {report.windows.excluded_recent_days} days
                                    are still settling.
                                </p>
                            </div>
                            {hasSearchData && (
                                <Badge variant="outline">
                                    {dataMode === 'saved'
                                        ? 'Saved measurements'
                                        : reading
                                          ? 'Updating measurements'
                                          : stale
                                            ? 'Saved measurements · update needed'
                                            : 'Latest measurements'}
                                </Badge>
                            )}
                        </div>
                    )}
                    {connected && !hasSearchData && report.pages.length > 0 && (
                        <p className="rounded-xl border bg-card/60 p-4 text-sm text-muted-foreground">
                            {reading
                                ? 'Your first search report is being collected. This page will update as data arrives.'
                                : paused
                                  ? 'Search Console is connected. Resume your project in Settings to collect new measurements.'
                                  : owner
                                    ? 'Search Console is connected. Refresh search data to collect measurements for your monitored pages.'
                                    : 'Search Console is connected. Your business owner can refresh search data to collect the first measurements.'}
                        </p>
                    )}
                    {currentSearchData && !currentSearchActivity && (
                        <p
                            className="rounded-xl border bg-card/60 p-4 text-sm text-muted-foreground"
                            role="status"
                        >
                            {dataMode === 'saved'
                                ? 'The saved Search Console report recorded no impressions or clicks for these observation dates.'
                                : 'Search Console returned measurements for this period, but recorded no impressions or clicks.'}
                        </p>
                    )}
                    {!compactExplorer && report.pages.length > 0 && (
                        <SearchExplorer report={report} />
                    )}
                    <details className={`${workspacePanelClass} p-5`}>
                        <summary className="cursor-pointer font-medium">
                            Performance after page improvements
                        </summary>
                        <div className="mt-5">
                            <ChangeFollowups report={report.changes} />
                        </div>
                    </details>
                    <details
                        id="reporting-connections"
                        className={`${workspacePanelClass} p-5`}
                    >
                        <summary className="cursor-pointer font-medium">
                            Reporting connections and data details
                        </summary>
                        <div className="mt-5 space-y-5">
                            <div className="grid gap-3 lg:grid-cols-3">
                                <SourceCard
                                    title="Google Search · pages"
                                    source={report.sources.gsc_pages}
                                />
                                <SourceCard
                                    title="Google Search · queries"
                                    source={report.sources.gsc_queries}
                                />
                                <SourceCard
                                    title="Website visits"
                                    source={
                                        report.sources
                                            .ga4_property_landing_paths
                                    }
                                />
                            </div>
                            {owner && project && (
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button asChild variant="outline">
                                        <a href={connectGoogle(project.id).url}>
                                            Connect or renew Google
                                        </a>
                                    </Button>
                                    <Link
                                        href={editProject(project.id)}
                                        className="text-sm underline"
                                    >
                                        Choose Google properties
                                    </Link>
                                </div>
                            )}
                            <details className="rounded-xl border p-4">
                                <summary className="cursor-pointer text-sm font-medium">
                                    Supplementary Analytics observations
                                </summary>
                                <div className="mt-4">
                                    <PropertyAnalytics report={report} />
                                </div>
                            </details>
                            <div className="space-y-2 text-xs leading-5 text-muted-foreground">
                                {report.notes.map((note) => (
                                    <p key={note}>{note}</p>
                                ))}
                                <Link
                                    href={feedbackIndex()}
                                    className="inline-block underline"
                                >
                                    Earlier article reports
                                </Link>
                            </div>
                        </div>
                    </details>
                </section>
            </WorkspacePage>
        </>
    );
}

const monitoredLabel = (count: number) =>
    count === 1 ? '1 monitored page' : `${count} monitored pages`;

function MonitoredPages({ pages }: { pages: Report['pages'] }) {
    if (pages.length === 0) {
        return (
            <p className="w-full text-sm text-muted-foreground">
                No website pages are monitored yet. Add pages to start a search
                report after Search Console is connected.
            </p>
        );
    }

    return (
        <div className="w-full border-t pt-4">
            <p className="text-sm font-medium">
                Monitoring {pages.length}{' '}
                {pages.length === 1 ? 'page' : 'pages'}
            </p>
            <ul className="mt-2 space-y-1 text-sm text-muted-foreground">
                {pages.slice(0, 5).map((page) => (
                    <li key={page.id} className="truncate">
                        <Link
                            href={`/pages/${page.id}`}
                            className="hover:underline"
                        >
                            {page.title || page.url}
                        </Link>
                    </li>
                ))}
            </ul>
            {pages.length > 5 && (
                <p className="mt-2 text-xs text-muted-foreground">
                    And {pages.length - 5} more monitored pages.
                </p>
            )}
        </div>
    );
}

function SourceCard({ title, source }: { title: string; source: Source }) {
    const timezone =
        typeof source.metadata.timeZone === 'string'
            ? source.metadata.timeZone
            : typeof source.metadata.timezone === 'string'
              ? source.metadata.timezone
              : null;

    return (
        <section className={`${workspacePanelClass} p-5`}>
            <h2 className="text-sm font-semibold">{title}</h2>
            <Badge
                className="mt-3"
                variant={
                    source.status === 'complete' && !source.stale
                        ? 'outline'
                        : 'secondary'
                }
            >
                {labels[source.status]}
            </Badge>
            {source.reason && (
                <p className="mt-3 text-sm leading-6 text-muted-foreground">
                    {source.reason}
                </p>
            )}
            <p className="mt-3 text-xs leading-5 text-muted-foreground">
                Last successful read: {when(source.last_successful_at)}
            </p>
            {source.stale && source.last_successful_at && (
                <p className="mt-2 text-xs text-amber-700 dark:text-amber-400">
                    Earlier observations are shown. This source does not have a
                    fresh complete read for the comparison.
                </p>
            )}
            {timezone && (
                <p className="mt-2 text-xs text-muted-foreground">
                    Reporting timezone: {timezone}
                </p>
            )}
        </section>
    );
}

function PropertyAnalytics({ report }: { report: Report }) {
    const analytics = report.analytics;

    return (
        <section
            className={`${workspacePanelClass} p-5 sm:p-6`}
            aria-labelledby="analytics-property-title"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id="analytics-property-title" className="font-semibold">
                    Analytics purchase reports
                </h2>
                <Badge variant="secondary">
                    Property paths · origin unknown
                </Badge>
            </div>
            <p className="mt-3 text-sm leading-6 text-muted-foreground">
                {analytics.reason} Paths matching the tracked pages and their
                verified aliases are selected at each read. A shared property
                can report the same path on several websites.
            </p>
            {report.sources.ga4_property_landing_paths.stale && (
                <p className="mt-3 text-xs text-amber-700 dark:text-amber-400">
                    Awaiting a fresh finished report. Any figures below are
                    earlier observations.
                </p>
            )}
            <div className="mt-4 overflow-x-auto">
                <table className="w-full min-w-[440px] text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs text-muted-foreground">
                            <th className="py-2">Selected path observations</th>
                            <th>Current 28 days</th>
                            <th>Previous 28 days</th>
                        </tr>
                    </thead>
                    <tbody>
                        <Metric
                            label="Sessions"
                            current={value(analytics.current.sessions)}
                            previous={value(analytics.previous.sessions)}
                        />
                        <Metric
                            label="Reported purchase events"
                            current={value(
                                analytics.current.reported_purchases,
                            )}
                            previous={value(
                                analytics.previous.reported_purchases,
                            )}
                        />
                        <Metric
                            label="Days with observed rows"
                            current={`${analytics.current.observed_days} / 28`}
                            previous={`${analytics.previous.observed_days} / 28`}
                        />
                    </tbody>
                </table>
            </div>
            <div className="mt-4 grid gap-5 sm:grid-cols-2">
                <Revenue analytics={analytics.current} label="Current" />
                <Revenue analytics={analytics.previous} label="Previous" />
            </div>
            <details className="mt-5 border-t pt-4">
                <summary className="cursor-pointer text-sm font-medium">
                    Unassigned landing paths ({analytics.paths.length})
                </summary>
                {analytics.paths.length === 0 ? (
                    <p className="mt-3 text-sm text-muted-foreground">
                        No path observations yet.
                    </p>
                ) : (
                    <div className="mt-3 max-h-80 overflow-auto">
                        <table className="w-full min-w-[480px] text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs text-muted-foreground">
                                    <th className="py-2">
                                        Path · website unknown
                                    </th>
                                    <th>Current purchases</th>
                                    <th>Previous purchases</th>
                                </tr>
                            </thead>
                            <tbody>
                                {analytics.paths.map((row) => (
                                    <tr className="border-b" key={row.path}>
                                        <td className="py-3 pr-4 break-all">
                                            {row.path}
                                        </td>
                                        <td>
                                            {value(
                                                row.current.reported_purchases,
                                            )}
                                        </td>
                                        <td>
                                            {value(
                                                row.previous.reported_purchases,
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </details>
            <p className="mt-4 text-xs leading-5 text-muted-foreground">
                Supplementary events with unknown consent coverage. These are
                never added to paid orders, assigned to your website pages, or
                counted as new customers.
            </p>
        </section>
    );
}

function Metric({
    label,
    current,
    previous,
}: {
    label: string;
    current: string;
    previous: string;
}) {
    return (
        <tr className="border-b last:border-0">
            <th className="py-3 text-left font-normal text-muted-foreground">
                {label}
            </th>
            <td className="py-3 tabular-nums">{current}</td>
            <td className="py-3 tabular-nums">{previous}</td>
        </tr>
    );
}
function Revenue({
    analytics,
    label,
}: {
    analytics: AnalyticsTotals;
    label: string;
}) {
    const money = (amount: number, currency: string) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
        }).format(amount / 1_000_000);

    return (
        <div className="text-xs leading-6">
            <h4 className="font-medium">{label} revenue and channels</h4>
            {analytics.revenue_by_currency.map(
                (row: AnalyticsTotals['revenue_by_currency'][number]) => (
                    <p key={row.currency}>
                        {money(row.gross_revenue_micros, row.currency)} gross ·{' '}
                        {money(row.refund_micros, row.currency)} refunds ·{' '}
                        {money(row.net_revenue_micros, row.currency)} net
                    </p>
                ),
            )}
            {analytics.channels.map((row) => (
                <p key={row.channel}>
                    {row.channel}: {row.sessions.toLocaleString()} sessions ·{' '}
                    {row.reported_purchases.toLocaleString()} reported purchases
                </p>
            ))}
            {analytics.sessions === null && (
                <p className="text-muted-foreground">Not observed.</p>
            )}
        </div>
    );
}
