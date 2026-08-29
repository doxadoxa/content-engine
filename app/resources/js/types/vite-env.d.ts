/// <reference types="vite/client" />

/*
 * The build-time contract.
 *
 * Vite's own types carry an index signature, so any `VITE_` name typechecks
 * whether or not it exists — which makes a typo in an env name a value that is
 * silently `undefined` at runtime rather than an error at build. Declaring the
 * ones we actually read narrows that, and doubles as the list of what has to
 * be present when the assets are compiled (see the frontend stage in the
 * Dockerfile, which is where these have to be set for a production build).
 */
interface ImportMetaEnv {
    /** Browser tab title. */
    readonly VITE_APP_NAME?: string;

    /**
     * The browser Sentry project. Empty in development and in CI, and empty is
     * a working value: resources/js/lib/observability.ts returns without
     * initialising anything.
     */
    readonly VITE_SENTRY_DSN?: string;

    /** Usually mirrors APP_ENV. Defaults to `local` when unset. */
    readonly VITE_SENTRY_ENVIRONMENT?: string;

    /**
     * Must match the server's `SENTRY_RELEASE` and the release the Vite plugin
     * uploads source maps under — otherwise the maps exist but Sentry will not
     * apply them, and stack traces stay minified.
     */
    readonly VITE_SENTRY_RELEASE?: string;

    /** Defaults to 0.1, matching `traces_sample_rate` in config/sentry.php. */
    readonly VITE_SENTRY_TRACES_SAMPLE_RATE?: string;
}
