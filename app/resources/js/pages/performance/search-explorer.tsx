import { Link } from '@inertiajs/react';
import { ArrowUpRight, FileText, Search } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Input } from '@/components/ui/input';
import { workspacePanelClass } from '@/components/workspace-page';
import type { Page, Report, SearchTotals } from './types';

type QueryRow = {
    query: string;
    page: Page;
    current: SearchTotals | undefined;
    previous: SearchTotals | undefined;
};
type Sort = 'clicks' | 'impressions' | 'position';
type Metrics = Pick<
    SearchTotals,
    'clicks' | 'impressions' | 'ctr' | 'position'
>;
export const format = (
    value: number | null | undefined,
    kind: 'count' | 'rate' | 'position' = 'count',
) =>
    value == null
        ? '—'
        : kind === 'rate'
          ? `${(value * 100).toFixed(1)}%`
          : kind === 'position'
            ? value.toFixed(1)
            : value.toLocaleString();
export const pagePath = (url: string) => {
    try {
        const parsed = new URL(url);

        return parsed.pathname + parsed.search;
    } catch {
        return url;
    }
};
const compare = (
    a: number | null | undefined,
    b: number | null | undefined,
    sort: Sort,
) => {
    if (a == null) {
        return b == null ? 0 : 1;
    }

    if (b == null) {
        return -1;
    }

    return sort === 'position' ? a - b : b - a;
};

export function SearchExplorer({ report }: { report: Report }) {
    const [view, setView] = useState<'queries' | 'pages'>('queries');
    const [search, setSearch] = useState('');
    const [pageId, setPageId] = useState('all');
    const [sort, setSort] = useState<Sort>('clicks');
    const queryRows: QueryRow[] = report.pages.flatMap((page) => {
        const current = new Map(
            page.current.queries.map((query) => [query.query, query]),
        );
        const previous = new Map(
            page.previous.queries.map((query) => [query.query, query]),
        );

        return [...new Set([...current.keys(), ...previous.keys()])].map(
            (query) => ({
                query,
                page,
                current: current.get(query),
                previous: previous.get(query),
            }),
        );
    });
    const term = search.trim().toLocaleLowerCase();
    const matchesPage = (page: Page) => pageId === 'all' || page.id === pageId;
    const filteredQueries = queryRows
        .filter(
            (row) =>
                matchesPage(row.page) &&
                `${row.query} ${row.page.title} ${row.page.url}`
                    .toLocaleLowerCase()
                    .includes(term),
        )
        .sort((a, b) => compare(a.current?.[sort], b.current?.[sort], sort));
    const pages = report.pages
        .filter(
            (page) =>
                matchesPage(page) &&
                `${page.title} ${page.url}`.toLocaleLowerCase().includes(term),
        )
        .sort((a, b) =>
            compare(a.current.search[sort], b.current.search[sort], sort),
        );
    const currentSource =
        report.sources[view === 'queries' ? 'gsc_queries' : 'gsc_pages'];
    const selectClass =
        'mt-1.5 h-10 w-full rounded-xl border bg-background px-3 text-sm';

    return (
        <section
            id="search-results"
            className={`${workspacePanelClass} min-w-0 scroll-mt-6 overflow-hidden`}
            aria-label="Google search detail"
        >
            <header className="space-y-5 border-b p-5 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div
                        className="flex gap-1 rounded-xl bg-muted p-1"
                        role="group"
                        aria-label="Search report view"
                    >
                        <button
                            type="button"
                            aria-pressed={view === 'queries'}
                            onClick={() => setView('queries')}
                            className={`inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium ${view === 'queries' ? 'bg-card shadow-sm' : 'text-muted-foreground'}`}
                        >
                            <Search className="size-4" />
                            Search queries
                        </button>
                        <button
                            type="button"
                            aria-pressed={view === 'pages'}
                            onClick={() => setView('pages')}
                            className={`inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium ${view === 'pages' ? 'bg-card shadow-sm' : 'text-muted-foreground'}`}
                        >
                            <FileText className="size-4" />
                            Pages
                        </button>
                    </div>
                    <p role="status" className="text-xs text-muted-foreground">
                        {view === 'queries'
                            ? `${filteredQueries.length} of ${queryRows.length} query and page matches`
                            : `${pages.length} of ${report.pages.length} monitored pages`}
                    </p>
                </div>
                <div>
                    <h2 className="font-semibold">
                        {view === 'queries'
                            ? 'What people search for'
                            : 'Which pages bring people to your website'}
                    </h2>
                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                        {view === 'queries'
                            ? 'The exact searches Google recorded, alongside the page it showed. A query can appear for more than one page.'
                            : 'Compare search performance across your monitored pages, including articles and service pages.'}
                    </p>
                </div>
                <div className="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                    <label className="min-w-0 text-xs font-medium">
                        {view === 'queries'
                            ? 'Find a query or page'
                            : 'Find a page'}
                        <Input
                            type="search"
                            className="mt-1.5"
                            placeholder={
                                view === 'queries'
                                    ? 'Search queries, titles, or URLs…'
                                    : 'Search titles or URLs…'
                            }
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </label>
                    <label className="min-w-0 text-xs font-medium">
                        Website page
                        <select
                            value={pageId}
                            onChange={(event) => setPageId(event.target.value)}
                            className={selectClass}
                        >
                            <option value="all">All monitored pages</option>
                            {report.pages.map((page) => (
                                <option key={page.id} value={page.id}>
                                    {page.title || pagePath(page.url)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="min-w-0 text-xs font-medium">
                        Sort by
                        <select
                            value={sort}
                            onChange={(event) =>
                                setSort(event.target.value as Sort)
                            }
                            className={selectClass}
                        >
                            <option value="clicks">Most clicks</option>
                            <option value="impressions">
                                Most impressions
                            </option>
                            <option value="position">Best position</option>
                        </select>
                    </label>
                </div>
            </header>
            {currentSource.stale && currentSource.last_successful_at && (
                <p className="border-b bg-amber-50/40 px-5 py-3 text-xs text-amber-800 dark:bg-amber-950/20 dark:text-amber-300">
                    Showing saved observations. Refresh search data for an
                    up-to-date comparison.
                </p>
            )}
            {view === 'queries' ? (
                filteredQueries.length > 0 ? (
                    <SearchTable query>
                        {filteredQueries.map((row) => (
                            <tr
                                key={`${row.page.id}:${row.query}`}
                                className="border-b last:border-0 hover:bg-muted/20"
                            >
                                <th
                                    scope="row"
                                    className="max-w-sm px-5 py-4 text-left text-sm leading-6 font-medium break-words"
                                >
                                    {row.query}
                                </th>
                                <td className="max-w-xs px-4 py-4 align-top">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setPageId(row.page.id);
                                            setSearch('');
                                        }}
                                        className="text-left text-sm leading-5 underline underline-offset-4"
                                    >
                                        {row.page.title ||
                                            pagePath(row.page.url)}
                                    </button>
                                    <p className="mt-1 text-xs break-all text-muted-foreground">
                                        {pagePath(row.page.url)}
                                    </p>
                                </td>
                                <MetricCells
                                    current={row.current}
                                    previous={row.previous}
                                    comparable={
                                        !report.sources.gsc_queries.stale &&
                                        row.current?.impressions != null &&
                                        row.previous?.impressions != null
                                    }
                                />
                            </tr>
                        ))}
                    </SearchTable>
                ) : (
                    <Empty
                        title={
                            queryRows.length === 0
                                ? 'No Google queries recorded yet'
                                : 'No queries match these filters'
                        }
                        description={
                            queryRows.length === 0
                                ? 'Query details appear here after Search Console returns measurements for your monitored pages.'
                                : 'Try another search or select all monitored pages.'
                        }
                    />
                )
            ) : pages.length > 0 ? (
                <SearchTable>
                    {pages.map((page) => (
                        <tr
                            key={page.id}
                            className="border-b last:border-0 hover:bg-muted/20"
                        >
                            <th
                                scope="row"
                                className="max-w-sm px-5 py-4 text-left align-top font-normal"
                            >
                                <Link
                                    href={`/pages/${page.id}`}
                                    className="text-sm leading-6 font-medium underline underline-offset-4"
                                >
                                    {page.title || pagePath(page.url)}
                                </Link>
                                <a
                                    href={page.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="mt-1 flex items-start gap-1 text-xs break-all text-muted-foreground hover:underline"
                                >
                                    {pagePath(page.url)}
                                    <ArrowUpRight className="mt-0.5 size-3 shrink-0" />
                                    <span className="sr-only">
                                        Open website in a new tab
                                    </span>
                                </a>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setView('queries');
                                        setPageId(page.id);
                                        setSearch('');
                                    }}
                                    className="mt-2 text-xs font-medium underline underline-offset-4"
                                >
                                    See queries
                                    <span className="sr-only">
                                        {' '}
                                        for {page.title || page.url}
                                    </span>
                                </button>
                            </th>
                            <MetricCells
                                current={page.current.search}
                                previous={page.previous.search}
                                comparable={page.search_comparison_available}
                            />
                        </tr>
                    ))}
                </SearchTable>
            ) : (
                <Empty
                    title={
                        report.pages.length === 0
                            ? 'No pages monitored yet'
                            : 'No pages match these filters'
                    }
                    description={
                        report.pages.length === 0
                            ? 'Choose your website pages using Manage pages above.'
                            : 'Try another search or select all monitored pages.'
                    }
                />
            )}
            <footer className="space-y-1 border-t px-5 py-4 text-xs leading-5 text-muted-foreground">
                <p>
                    Changes compare the previous 28 days when both periods have
                    data. Average position: a lower number is better.
                    Click-through rate (CTR) is the share of impressions that
                    became clicks.
                </p>
                <p>
                    — means no recorded data.{' '}
                    {view === 'queries'
                        ? 'Google omits some queries, so query totals may differ from page totals.'
                        : 'Only monitored pages are included.'}{' '}
                    Search dates use Pacific time.
                </p>
            </footer>
        </section>
    );
}

function SearchTable({
    children,
    query = false,
}: {
    children: ReactNode;
    query?: boolean;
}) {
    return (
        <>
            <p className="border-b px-5 py-2 text-xs text-muted-foreground xl:hidden">
                Scroll sideways to see all measurements.
            </p>
            <div
                className="overflow-x-auto"
                tabIndex={0}
                role="region"
                aria-label={
                    query
                        ? 'Search query measurements; scroll horizontally on small screens'
                        : 'Page measurements; scroll horizontally on small screens'
                }
            >
                <table
                    className={`w-full text-sm ${query ? 'min-w-[860px]' : 'min-w-[640px]'}`}
                >
                    <caption className="sr-only">
                        {query
                            ? 'Google search queries by page'
                            : 'Monitored page performance'}{' '}
                        for the current 28 days, with available changes from the
                        previous 28 days
                    </caption>
                    <thead>
                        <tr className="border-b bg-muted/20 text-left text-xs text-muted-foreground">
                            <th scope="col" className="px-5 py-3">
                                {query ? 'Search query' : 'Website page'}
                            </th>
                            {query && (
                                <th scope="col" className="px-4 py-3">
                                    Page shown
                                </th>
                            )}
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
                    <tbody>{children}</tbody>
                </table>
            </div>
        </>
    );
}
/**
 * The change as it will be displayed, positive meaning better: rounded to
 * one decimal (CTR in percentage points), so a change too small to show
 * reads as "No change" instead of a signed zero. Lower positions are better.
 */
export const changeSize = (
    metric: 'clicks' | 'impressions' | 'ctr' | 'position',
    current: number,
    previous: number,
) => {
    const raw =
        metric === 'position'
            ? previous - current
            : metric === 'ctr'
              ? (current - previous) * 100
              : current - previous;
    const rounded =
        metric === 'clicks' || metric === 'impressions'
            ? Math.round(raw)
            : Math.round(raw * 10) / 10;

    return rounded === 0 ? 0 : rounded;
};

export function MetricCells({
    current,
    previous,
    comparable,
}: {
    current?: Metrics | null;
    previous?: Metrics | null;
    comparable: boolean;
}) {
    return (
        <>
            {(['clicks', 'impressions', 'ctr', 'position'] as const).map(
                (metric) => (
                    <td
                        key={metric}
                        className="px-4 py-4 align-top tabular-nums"
                    >
                        <p className="font-medium whitespace-nowrap">
                            {format(
                                current?.[metric],
                                metric === 'ctr'
                                    ? 'rate'
                                    : metric === 'position'
                                      ? 'position'
                                      : 'count',
                            )}
                        </p>
                        <Change
                            current={current?.[metric]}
                            previous={previous?.[metric]}
                            metric={metric}
                            comparable={comparable}
                        />
                    </td>
                ),
            )}
        </>
    );
}
function Change({
    current,
    previous,
    metric,
    comparable,
}: {
    current?: number | null;
    previous?: number | null;
    metric: 'clicks' | 'impressions' | 'ctr' | 'position';
    comparable: boolean;
}) {
    const previousLabel = format(
        previous,
        metric === 'ctr'
            ? 'rate'
            : metric === 'position'
              ? 'position'
              : 'count',
    );

    if (!comparable || current == null || previous == null) {
        return previous == null ? null : (
            <p className="mt-1 text-xs whitespace-nowrap text-muted-foreground">
                <span className="sr-only">Previous 28 days: </span>
                <span aria-hidden="true">Prev </span>
                {previousLabel}
            </p>
        );
    }

    const delta = changeSize(metric, current, previous);
    const amount =
        metric === 'ctr'
            ? `${Math.abs(delta).toFixed(1)} pp`
            : metric === 'position'
              ? Math.abs(delta).toFixed(1)
              : Math.abs(delta).toLocaleString();

    return (
        <p
            className={`mt-1 text-xs whitespace-nowrap ${delta > 0 ? 'text-emerald-800 dark:text-emerald-300' : delta < 0 ? 'text-amber-800 dark:text-amber-300' : 'text-muted-foreground'}`}
            title={`Previous 28 days: ${previousLabel}`}
        >
            <span className="sr-only">Change from previous 28 days: </span>
            {delta === 0
                ? 'No change'
                : metric === 'position'
                  ? `${delta > 0 ? 'Up' : 'Down'} ${amount}`
                  : `${delta > 0 ? '+' : '−'}${amount}`}
            <span className="sr-only">. Previous 28 days: {previousLabel}</span>
        </p>
    );
}
function Empty({ title, description }: { title: string; description: string }) {
    return (
        <div className="px-6 py-12 text-center">
            <Search className="mx-auto mb-3 size-7 text-muted-foreground" />
            <h3 className="font-medium">{title}</h3>
            <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-muted-foreground">
                {description}
            </p>
        </div>
    );
}
