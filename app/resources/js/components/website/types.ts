/** What `ConnectionHealth::for()` says about one website connection. */
export type ConnectionHealth = {
    state: 'connected' | 'testing' | 'failed' | 'untested' | 'paused';
    headline: string;
    detail: string | null;
    checked_at: string | null;
};

/** One website connection, as the website page receives it. */
export type WebsiteConnection = {
    id: string;
    name: string;
    type: string;
    type_label: string;
    is_enabled: boolean;
    /** Whether a secret is stored — never the secret itself. */
    has_secret: boolean;
    native_config: {
        page_receiver_base: string;
        username: string;
        endpoint: string;
    };
    can_schedule_articles: boolean;
    can_test: boolean;
    verified_at: string | null;
    health: ConnectionHealth;
    test_pending: boolean;
    target: string | null;
    created_at: string | null;
};

/** "30 Sep, 14:02" in the reader's own time zone and language. */
export function formatCheckedAt(iso: string | null): string | null {
    if (!iso) {
        return null;
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return new Intl.DateTimeFormat(undefined, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

/**
 * The WordPress plugin's REST base for a site address:
 * https://example.com → https://example.com/wp-json/avyo/v1.
 */
export function wordPressApiBase(siteAddress: string): string {
    try {
        const url = new URL(siteAddress);

        return `${url.origin}${url.pathname.replace(/\/$/, '')}/wp-json/avyo/v1`;
    } catch {
        return '';
    }
}

/** The site address back from an API base, for editing. */
export function wordPressSiteAddress(apiBase: string): string {
    return apiBase.replace(/\/wp-json\/avyo\/v1\/?$/, '');
}
