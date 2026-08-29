import { createInertiaApp } from '@inertiajs/react';
import * as Sentry from '@sentry/react';
import CookieConsent from '@/components/cookie-consent';
import ErrorFallback from '@/components/error-fallback';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import MarketingLayout from '@/layouts/marketing-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { initSentry } from '@/lib/observability';

const appName = import.meta.env.VITE_APP_NAME || 'Avyo';

/*
 * Before the app is created, so that a failure while creating it is still
 * reported. Does nothing at all when no DSN was compiled in — see
 * lib/observability.ts, which also explains why errors are not consent-gated
 * and tracing is.
 */
initSentry();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'marketing':
                return MarketingLayout;
            // The public documents, which are read by people who have no
            // account and often no intention of getting one. Same bare frame as
            // the landing page: a signed-in shell around a privacy policy would
            // put a project switcher above it.
            case name.startsWith('legal/'):
                return MarketingLayout;
            // Seen by people who are not signed in, so no signed-in frame: a
            // project switcher listing projects they cannot reach, above a
            // navigation column of sections they cannot open.
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            /* Outside the providers rather than inside, because a provider is
               as capable of throwing as the page it wraps — and a boundary
               mounted underneath one cannot catch the thing that took it down.
               React unmounts the whole tree below a boundary that catches, so
               what renders here has to stand on its own: see error-fallback. */
            <Sentry.ErrorBoundary fallback={<ErrorFallback />}>
                <TooltipProvider delayDuration={0}>
                    {app}
                    <Toaster />
                    {/* Mounted here rather than in a layout so the answer survives
                        moving between the landing page, the login form and the
                        product — consent belongs to the visitor, not the screen. */}
                    <CookieConsent />
                </TooltipProvider>
            </Sentry.ErrorBoundary>
        );
    },
    progress: {
        color: '#4f46e5',
    },
});

initializeTheme();
