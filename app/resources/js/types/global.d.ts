import '@inertiajs/core';

declare module 'react' {
    // Safari reads this to generate a password that satisfies our rules.
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}
import type { Auth } from '@/types/auth';

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
            [key: string]: unknown;
        };
    }
}
