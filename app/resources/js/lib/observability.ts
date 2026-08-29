import * as Sentry from '@sentry/react';
import { hasConsent, whenGranted } from '@/lib/consent';

/*
 * What the browser reports when it breaks, and what it is allowed to measure.
 *
 * These are two different questions with two different answers, which is the
 * whole reason this file is not three lines of `Sentry.init`.
 *
 * **Errors go out for everyone.** A JavaScript exception is not a measurement
 * of a visitor — it is a defect report about us. In the configuration below the
 * SDK sets no cookie, writes nothing to storage, and assigns nobody an
 * identifier that survives the page, so there is nothing here that PECR's
 * consent rule attaches to. And the alternative is worse than merely
 * cautious: gating errors on the banner would blind us precisely on the
 * screens people see before they answer it — the landing page, the sign-up
 * form, the checkout — which is where a break costs the most and where we
 * would never hear about it.
 *
 * **Performance tracing waits to be asked.** Tracing times navigations and
 * requests across a visit, which is measurement of the visitor's session
 * however useful the output is to us, and it is exactly what the `analytics`
 * category was created to cover. So it goes through the same gate as any tag
 * would — see resources/js/lib/consent.ts, whose docblock asks for precisely
 * this and warns that a gate which only opens is not a gate.
 *
 * That warning is why tracing is gated twice below rather than once. Sentry's
 * client can be handed an integration at runtime but offers no way to take one
 * back, so `whenGranted`'s teardown cannot uninstall the instrumentation the
 * way the consent store expects. What it can do — and what actually matters —
 * is stop the data: the sampler is consulted per transaction and reads consent
 * live, so switching analytics off in the preferences panel means the very next
 * navigation records nothing and sends nothing, without a reload. The
 * `whenGranted` half then keeps the instrumentation from being installed at all
 * for anyone who has not said yes.
 *
 * With no `VITE_SENTRY_DSN` compiled in, none of this runs at all.
 */

/*
 * Read at build time, not at runtime: Vite substitutes these literally, so a
 * build without a DSN contains no DSN to find. Empty string when unset, which
 * is why the check below is a truthiness check rather than a null one.
 */
const dsn = import.meta.env.VITE_SENTRY_DSN;

export function initSentry(): void {
    if (!dsn) {
        return;
    }

    Sentry.init({
        dsn,
        environment: import.meta.env.VITE_SENTRY_ENVIRONMENT || 'local',
        release: import.meta.env.VITE_SENTRY_RELEASE || undefined,

        /*
         * The browser half of the same promise config/sentry.php makes on the
         * server: no IP address, no cookies, no form values pulled off the
         * page. What identifies a report here is the URL and the stack.
         */
        sendDefaultPii: false,

        /*
         * Deliberately empty, which turns off Sentry's default set as well.
         *
         * The defaults are mostly harmless (breadcrumbs, global handlers), but
         * `browserTracingIntegration` is among the things that would otherwise
         * be assembled for us, and starting it here would start measuring
         * before the banner has been answered. Tracing is added below, on
         * consent, and nowhere else.
         */
        integrations: [],

        /*
         * A function rather than a rate, and this is the part that makes
         * withdrawal work.
         *
         * Sentry's client can gain an integration at runtime (`addIntegration`)
         * but has no API to drop one again — so "stop tracing" cannot be
         * expressed by removing what "start tracing" added. It can be expressed
         * here: the sampler is consulted at the moment a transaction begins, so
         * reading consent inside it means the first navigation after somebody
         * switches analytics off is already unsampled. Nothing is recorded and
         * nothing is sent, on the page they are standing on, with no reload.
         *
         * The rate itself matches the server's tenth.
         */
        tracesSampler: () =>
            hasConsent('analytics')
                ? Number(import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? 0.1)
                : 0,
    });

    /*
     * Two gates, not one, because they close different things.
     *
     * The sampler above is what stops data. This is what stops the
     * instrumentation being installed in the first place: somebody who never
     * answers the banner, or answers no, never has their history, fetch and
     * XHR patched at all. Consent withdrawn after the fact leaves that patching
     * in place — it is not removable — but it is then wrapping a sampler that
     * returns zero, so it collects nothing, sends nothing, and adds nothing to
     * any request but our own.
     *
     * Guarded against a second grant adding a second copy: `whenGranted` runs
     * `setup` on every transition into the allowed state, and the pair of
     * integrations that results would double every span.
     */
    let tracingInstalled = false;

    whenGranted('analytics', () => {
        if (tracingInstalled) {
            return;
        }

        tracingInstalled = true;
        Sentry.addIntegration(Sentry.browserTracingIntegration());
    });
}
