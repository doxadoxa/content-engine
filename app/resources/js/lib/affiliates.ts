import { hasConsent, subscribe, whenGranted } from '@/lib/consent';

/*
 * Crediting the partners who send people here.
 *
 * Anderro's script notices a `?ref=` link, writes a visitor id on our domain,
 * and reports the click. The server does the rest: it reads that cookie when an
 * account is made and tells Anderro who signed up — see app/Affiliates.
 *
 * **Marketing consent, not `data-auto` on a tag in the layout.** Anderro's guide
 * puts the script in every page's head with auto-tracking on, which is its mode
 * for markets where nobody has to be asked. Here somebody does: the cookie it
 * writes identifies a browser for a year so a third party can link it to a
 * purchase, which is exactly what the `marketing` category exists for. So it
 * goes through the gate consent.ts asks every tag to use, and `data-auto` is set
 * only on a script that is loaded because the answer was already yes.
 *
 * **Only for somebody a partner sent.** Consent makes loading the script
 * allowed, not useful: for a visitor who found us on their own it would do
 * nothing but hand Anderro their address and browser, and write a year-long
 * identifier nobody will ever credit. So it is loaded only when there is a
 * referral to record — a `?ref=` on the landing address, or the referral cookie
 * from an earlier visit through a partner's link.
 *
 * **Why the landing address is remembered.** The script reads `?ref=` from the
 * address bar at the moment it runs. Somebody who arrives through a partner's
 * link, clicks through two pages, and only then answers the banner would have
 * lost the code by then — Inertia moves the address bar without reloading. So
 * the code is read once, when this module is first evaluated, and held in
 * memory only: nothing is stored until consent exists, and then it is handed
 * to the script in the cookie the script already reads it from. Only codes
 * made of URL-safe characters are carried that way. The script stores what it
 * reads from that cookie without decoding it and encodes it again on the way
 * back in, so anything that needs escaping would reach Anderro mangled.
 *
 * **Withdrawal reloads the page.** The script cannot be unloaded — it keeps
 * listeners on the document and an observer on the DOM — so the teardown
 * deletes its cookies, removes what it can, and reloads, which is what
 * consent.ts says an honest teardown does when a vendor leaves no other way.
 */

/* Both written by Anderro's script. Asserted against the cookie policy in
 * tests/Feature/Legal, and read by name in app/Affiliates/Referrals.php. */
export const VISITOR_COOKIE = '_anderro_vid';
export const REFERRAL_COOKIE = '_anderro_ref';

const SCRIPT_SRC = 'https://track.anderro.com/a.js';

/* How long the script itself keeps a referral code. */
const REFERRAL_MAX_AGE_SECONDS = 60 * 60 * 24 * 90;

const CARRIABLE_REFERRAL = /^[A-Za-z0-9_-]{1,100}$/;

declare global {
    interface Window {
        anderro?: {
            optIn: () => void;
            getVisitorId: () => string;
        };
    }
}

function publicKey(): string | null {
    const key = document
        .querySelector('meta[name="anderro-key"]')
        ?.getAttribute('content');

    return key ? key : null;
}

function referralInAddress(): string | null {
    const code = new URLSearchParams(window.location.search).get('ref');

    return code && code.trim() !== '' ? code : null;
}

const landingReferral =
    typeof window === 'undefined' ? null : referralInAddress();

/*
 * The domain the script scopes its cookies to, handed to it as `data-domain`
 * so that its writes and the deletions below cannot disagree about which
 * cookie they mean.
 *
 * This host, not the script's default. Left alone it scopes them to the last
 * two labels — `avyo.ai` for `cm.avyo.ai` — which sends them to every site
 * under that name, none of which asked the question our banner asked. Null for
 * a host the script would not scope anyway (`localhost`, an IP address), where
 * the cookies are host-only.
 */
function cookieDomain(): string | null {
    const host = window.location.hostname;

    if (!host.includes('.') || /^[\d.]+$/.test(host) || host.includes(':')) {
        return null;
    }

    return host;
}

function writeCookie(
    name: string,
    value: string,
    maxAge: number,
    domain: string | null,
): void {
    const scope = domain ? `; domain=${domain}` : '';

    document.cookie = `${name}=${value}; path=/; max-age=${maxAge}${scope}; SameSite=Lax`;
}

function hasCookie(name: string): boolean {
    return document.cookie
        .split('; ')
        .some((part) => part.startsWith(`${name}=`));
}

/* Both scopes, because a cookie written before `data-domain` was pinned, or on
 * a host where it resolves differently, is still a cookie that has to go. */
function deleteCookies(): void {
    const domain = cookieDomain();

    for (const name of [VISITOR_COOKIE, REFERRAL_COOKIE]) {
        writeCookie(name, '', 0, null);

        if (domain) {
            writeCookie(name, '', 0, domain);
        }
    }
}

function load(key: string): (() => void) | void {
    if (!landingReferral && !hasCookie(REFERRAL_COOKIE)) {
        return;
    }

    const domain = cookieDomain();

    // The code this visit arrived with, if the address bar has lost it since.
    if (
        landingReferral &&
        CARRIABLE_REFERRAL.test(landingReferral) &&
        !referralInAddress()
    ) {
        writeCookie(
            REFERRAL_COOKIE,
            landingReferral,
            REFERRAL_MAX_AGE_SECONDS,
            domain,
        );
    }

    const script = document.createElement('script');
    script.src = SCRIPT_SRC;
    script.async = true;
    script.dataset.key = key;
    script.dataset.auto = 'true';

    if (domain) {
        script.dataset.domain = domain;
    }

    document.head.appendChild(script);

    return () => {
        script.remove();
        delete window.anderro;
        deleteCookies();
        window.location.reload();
    };
}

/**
 * Start affiliate tracking for anybody who allows marketing cookies. Does
 * nothing on an installation with no Anderro keys.
 */
export function initAffiliateTracking(): void {
    const key = publicKey();

    if (!key) {
        return;
    }

    /*
     * Cookies left from an answer that no longer stands — a consent inventory
     * that has moved on since, or a refusal given on another tab — go now
     * rather than riding along on the next sign-up. The server checks consent
     * before using them too; this is so they are not kept at all.
     */
    const forgetWithoutConsent = (): void => {
        if (
            !hasConsent('marketing') &&
            (hasCookie(VISITOR_COOKIE) || hasCookie(REFERRAL_COOKIE))
        ) {
            deleteCookies();
        }
    };

    forgetWithoutConsent();
    subscribe(forgetWithoutConsent);

    whenGranted('marketing', () => load(key));
}
