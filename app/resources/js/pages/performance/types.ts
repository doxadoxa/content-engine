import type { ChangeReport } from './change-followups';

export type SearchTotals = {
    impressions: number | null;
    clicks: number | null;
    ctr: number | null;
    position: number | null;
    observed_days: number;
    expected_days: number;
};
export type Source = {
    status:
        | 'not_read'
        | 'reading'
        | 'complete'
        | 'partial'
        | 'unavailable'
        | 'incompatible'
        | 'failed';
    reason: string | null;
    finished_at: string | null;
    last_successful_at: string | null;
    stale: boolean;
    covers_requested_window: boolean;
    metadata: Record<string, unknown>;
};
export type AnalyticsTotals = {
    supplementary: true;
    sessions: number | null;
    reported_purchases: number | null;
    observed_days: number;
    expected_days: number;
    revenue_by_currency: {
        currency: string;
        gross_revenue_micros: number;
        refund_micros: number;
        net_revenue_micros: number;
    }[];
    channels: {
        channel: string;
        sessions: number;
        reported_purchases: number;
    }[];
};
export type Period = {
    search: SearchTotals;
    queries: (SearchTotals & { query: string })[];
    analytics: AnalyticsTotals;
};
export type Page = {
    id: string;
    url: string;
    title: string;
    current: Period;
    previous: Period;
    search_comparison_available: boolean;
    search_appearance: 'observed' | 'unknown';
    daily_search: {
        day: string;
        impressions: number;
        clicks: number;
        position: number | null;
    }[];
};
export type Report = {
    changes: ChangeReport;
    windows: {
        current: { from: string; to: string; days: number };
        previous: { from: string; to: string; days: number };
        excluded_recent_days: number;
        search_timezone: string;
    };
    sources: Record<
        'gsc_pages' | 'gsc_queries' | 'ga4_property_landing_paths',
        Source
    >;
    analytics: {
        scope: 'selected_landing_paths_in_property';
        landing_origin: null;
        reason: string;
        current: AnalyticsTotals;
        previous: AnalyticsTotals;
        paths: {
            path: string;
            current: AnalyticsTotals;
            previous: AnalyticsTotals;
        }[];
    };
    pages: Page[];
    notes: string[];
};

/** Whole-property Search Console totals; `ctr` is a 0–1 fraction. */
export type SiteSearchTotals = {
    clicks: number;
    impressions: number;
    ctr: number | null;
    position: number | null;
};
export type SiteSearchState =
    | 'not_connected'
    | 'no_property'
    | 'reading'
    | 'ready'
    | 'no_data'
    | 'failed'
    | 'paused';
type SiteSearchWindow = { from: string; to: string; days: number };
export type SiteSearch = {
    state: SiteSearchState;
    property: string | null;
    reason: string | null;
    reading: boolean;
    updated_at: string | null;
    windows: { current: SiteSearchWindow; previous: SiteSearchWindow };
    current: SiteSearchTotals | null;
    previous: SiteSearchTotals | null;
    history_from: string | null;
    daily: {
        day: string;
        clicks: number;
        impressions: number;
        position: number | null;
    }[];
    top_queries: (SiteSearchTotals & {
        query: string;
        previous: SiteSearchTotals | null;
    })[];
    top_pages: (SiteSearchTotals & {
        url: string;
        previous: SiteSearchTotals | null;
        tracked: boolean;
        page_id: string | null;
    })[];
};

/** "sc-domain:example.com" or "https://example.com/" → "example.com". */
export const propertyHost = (property: string | null) => {
    if (!property) {
        return null;
    }

    if (property.startsWith('sc-domain:')) {
        return property.slice('sc-domain:'.length);
    }

    try {
        return new URL(property).host;
    } catch {
        return property;
    }
};
