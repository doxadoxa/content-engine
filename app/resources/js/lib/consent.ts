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
 * Return a teardown from it and that runs the moment consent is taken away
 * again — see the gate below for why that half is not optional.
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
    reconcile();
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

/*
 * Consent runs in both directions.
 *
 * The first version of this fired each callback once and then forgot it, which
 * made withdrawal a lie. Somebody who accepted analytics, then reopened the
 * panel and switched it off, got a rewritten cookie and a tracker that carried
 * on collecting until they happened to reload — while three pages of ours told
 * them withdrawing was as easy as giving. A gate that only opens is not a gate.
 *
 * So an integration is a pair, not a callback: the `setup` that starts it and
 * the teardown `setup` returns. This reconciles the pair against the current
 * answer every time the answer changes — starting what has become allowed,
 * stopping what has become forbidden, and doing neither twice.
 *
 * A vendor whose script cannot truly be unloaded is not an excuse to skip the
 * teardown; it is a reason for that teardown to remove what it can and then
 * reload the page, which is the only honest way to stop code somebody has
 * withdrawn permission for.
 */

type Teardown = (() => void) | void;

type Integration = {
    category: OptionalCategory;
    setup: () => Teardown;
    /* Non-null exactly while this integration is running, which is also how a
     * second grant is stopped from starting a second copy of it. */
    stop: (() => void) | null;
};

const NOTHING_TO_UNDO = (): void => {};

const integrations = new Set<Integration>();

function reconcile(): void {
    for (const integration of integrations) {
        const allowed = hasConsent(integration.category);

        if (allowed && integration.stop === null) {
            const stop = integration.setup();
            integration.stop =
                typeof stop === 'function' ? stop : NOTHING_TO_UNDO;

            continue;
        }

        if (!allowed && integration.stop !== null) {
            const stop = integration.stop;
            /* Cleared first, so a teardown that throws cannot leave the
             * integration looking like it is still running. */
            integration.stop = null;
            stop();
        }
    }
}

/**
 * Start something when this category is allowed, and stop it when it is not.
 *
 * `setup` runs immediately if consent already exists, on the click if it is
 * given later, and never if it is refused. Whatever it returns is treated as
 * the way to undo it and is called if consent is withdrawn; returning nothing
 * is a promise that there is nothing to undo.
 *
 * This is the only correct place to put a tag. Anything that injects a script
 * outside it is a script that runs before consent — or after it is taken back.
 *
 * Returns an unregister function, which also stops the integration.
 */
export function whenGranted(
    category: OptionalCategory,
    setup: () => Teardown,
): () => void {
    const integration: Integration = { category, setup, stop: null };

    integrations.add(integration);
    reconcile();

    return () => {
        integrations.delete(integration);

        if (integration.stop !== null) {
            const stop = integration.stop;
            integration.stop = null;
            stop();
        }
    };
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
