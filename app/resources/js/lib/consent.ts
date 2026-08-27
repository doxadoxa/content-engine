/*
 * What we are allowed to store on somebody's device, and a way to ask.
 *
 * The point of this file is that the banner it powers is not decoration. A
 * consent dialog that records a choice nobody consults is worse than no dialog
 * — it is a claim, made to every visitor, that we honour something we do not.
 * So the rule here is: a category only exists if refusing it changes what runs.
 *
 * Today that means `analytics` and `marketing`, both empty and both off until
 * somebody says otherwise. The essential and preference cookies are not
 * offered, because they are not a question — see config/legal.php for why the
 * theme cookie is on that side of the line.
 *
 * To add analytics later, do not reach for the layout. Call
 * `whenGranted('analytics', () => { ...inject the tag... })` and it will run
 * when consent already exists, the moment it is given, and never otherwise.
 */

export const OPTIONAL_CATEGORIES = ['analytics', 'marketing'] as const;

export type OptionalCategory = (typeof OPTIONAL_CATEGORIES)[number];

export type Consent = Record<OptionalCategory, boolean>;

/*
 * The record we keep. `v` is the version of the cookie inventory the person was
 * shown: bump `legal.consent_version` when what we ask for changes and every
 * stored answer becomes stale, because consent to the old list is not consent
 * to the new one. `at` is the timestamp, which is the part that makes a consent
 * record evidence rather than a preference.
 */
type StoredConsent = Consent & { v: string; at: string };

const COOKIE = 'avyo_consent';

/* Twelve months, which is the outer edge of what ICO treats as a reasonable
 * interval before asking again. */
const MAX_AGE_SECONDS = 60 * 60 * 24 * 365;

const DENIED: Consent = { analytics: false, marketing: false };

const GRANTED: Consent = { analytics: true, marketing: true };

/*
 * Rendered into the document head by app.blade.php. Read once per page load:
 * it cannot change under us, and the banner asks for it on every render.
 */
function inventoryVersion(): string {
    if (typeof document === 'undefined') {
        return '0';
    }

    return (
        document
            .querySelector('meta[name="consent-version"]')
            ?.getAttribute('content') ?? '0'
    );
}

function readCookie(): StoredConsent | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const raw = document.cookie
        .split('; ')
        .find((part) => part.startsWith(`${COOKIE}=`))
        ?.slice(COOKIE.length + 1);

    if (!raw) {
        return null;
    }

    try {
        const parsed = JSON.parse(decodeURIComponent(raw)) as StoredConsent;

        /*
         * A record answering a different question. Treated as no record at all
         * rather than as a partial yes: the safe reading of an outdated answer
         * is the one that stores less.
         */
        if (parsed.v !== inventoryVersion()) {
            return null;
        }

        return {
            ...parsed,
            analytics: parsed.analytics === true,
            marketing: parsed.marketing === true,
        };
    } catch {
        /* Somebody hand-edited it, or a previous version wrote a different
         * shape. Either way we do not know what they agreed to. */
        return null;
    }
}

function writeCookie(consent: Consent): StoredConsent {
    const record: StoredConsent = {
        ...consent,
        v: inventoryVersion(),
        at: new Date().toISOString(),
    };

    const value = encodeURIComponent(JSON.stringify(record));
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';

    /* Lax rather than Strict: this is not a credential, and Strict would hide
     * the record from the first page of anyone arriving from a link, which
     * re-asks people who have already answered. */
    document.cookie = `${COOKIE}=${value}; path=/; max-age=${MAX_AGE_SECONDS}; SameSite=Lax${secure}`;

    return record;
}

/* ------------------------------------------------------------------ *
 * The store
 * ------------------------------------------------------------------ */

type Listener = () => void;

const listeners = new Set<Listener>();

/*
 * Cached so `getSnapshot` can be called as often as React likes and still
 * return a referentially stable object — reparsing the cookie each time would
 * hand back a new object every render and spin `useSyncExternalStore`.
 */
let snapshot: { decided: boolean; consent: Consent } = {
    decided: false,
    consent: DENIED,
};

function refresh(): void {
    const stored = readCookie();

    snapshot = stored
        ? {
              decided: true,
              consent: {
                  analytics: stored.analytics,
                  marketing: stored.marketing,
              },
          }
        : { decided: false, consent: DENIED };
}

refresh();

function emit(): void {
    refresh();
    listeners.forEach((listener) => listener());
    grantWaiters();
}

export function subscribe(listener: Listener): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export function getSnapshot(): { decided: boolean; consent: Consent } {
    return snapshot;
}

/** What was agreed to. Everything off until somebody has actually answered. */
export function currentConsent(): Consent {
    return snapshot.consent;
}

/** Whether this person has answered the banner at all, under the current inventory. */
export function hasDecided(): boolean {
    return snapshot.decided;
}

export function hasConsent(category: OptionalCategory): boolean {
    return snapshot.consent[category] === true;
}

export function acceptAll(): void {
    writeCookie(GRANTED);
    emit();
}

export function rejectAll(): void {
    writeCookie(DENIED);
    emit();
}

export function saveConsent(consent: Consent): void {
    writeCookie(consent);
    emit();
}

/* ------------------------------------------------------------------ *
 * The gate
 * ------------------------------------------------------------------ */

const waiting = new Map<OptionalCategory, Set<() => void>>();

function grantWaiters(): void {
    for (const category of OPTIONAL_CATEGORIES) {
        if (!hasConsent(category)) {
            continue;
        }

        const callbacks = waiting.get(category);

        if (!callbacks) {
            continue;
        }

        /* Cleared before running: each callback loads a script or sets up a
         * tracker, and doing that twice because consent was re-saved is a
         * duplicate pageview at best. */
        waiting.delete(category);
        callbacks.forEach((callback) => callback());
    }
}

/**
 * Run `callback` once, as soon as this category is allowed — immediately if it
 * already is, on the click if it is not yet, and never if it is refused.
 *
 * This is the only correct place to put a tag. Anything that injects a script
 * outside it is a script that runs before consent.
 */
export function whenGranted(
    category: OptionalCategory,
    callback: () => void,
): void {
    if (hasConsent(category)) {
        callback();

        return;
    }

    const callbacks = waiting.get(category) ?? new Set<() => void>();
    callbacks.add(callback);
    waiting.set(category, callbacks);
}

/* ------------------------------------------------------------------ *
 * Reopening the choice
 * ------------------------------------------------------------------ */

const OPEN_EVENT = 'avyo:cookie-preferences';

/**
 * Withdrawing has to be as easy as giving, so every page that mentions cookies
 * carries a link back to this. Fired as a DOM event rather than held in React
 * state because the callers are spread across the marketing footer, the cookie
 * policy, and the product shell, and none of them share a provider.
 */
export function openPreferences(): void {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT));
}

export function onOpenPreferences(listener: () => void): () => void {
    window.addEventListener(OPEN_EVENT, listener);

    return () => window.removeEventListener(OPEN_EVENT, listener);
}
