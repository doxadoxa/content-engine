import { createInertiaApp } from '@inertiajs/react';
import CookieConsent from '@/components/cookie-consent';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import MarketingLayout from '@/layouts/marketing-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Avyo';

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
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
                {/* Mounted here rather than in a layout so the answer survives
                    moving between the landing page, the login form and the
                    product — consent belongs to the visitor, not the screen. */}
                <CookieConsent />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4f46e5',
    },
});

initializeTheme();
