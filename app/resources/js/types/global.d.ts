import '@inertiajs/core';

declare module 'react' {
    // Safari reads this to generate a password that satisfies our rules.
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}
import type { Auth } from '@/types/auth';
import type { Billing } from '@/types/billing';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            /**
             * Which optional features this deployment runs. Shared on every
             * page because the sidebar is on every page and it is what decides
             * whether an entry is drawn at all — the routes behind a disabled
             * feature do not exist, so a hidden entry is the only honest shape.
             */
            social: { enabled: boolean };
            /**
             * What the current project may do. Null when there is no project
             * to say it about — a guest, or somebody still in the onboarding
             * wizard, neither of whom has a subscription to describe.
             */
            billing: Billing | null;
            [key: string]: unknown;
        };
    }
}
