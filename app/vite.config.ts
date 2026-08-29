import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { sentryVitePlugin } from '@sentry/vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

// Wayfinder shells out to `php artisan` to generate the typed route helpers.
// The Docker asset stage runs on a Node image with no PHP, so there the helpers
// are generated in an earlier stage and copied in, and the plugin is dropped.
// See the Dockerfile.
const skipWayfinder = process.env.WAYFINDER_SKIP === 'true';

// Wayfinder writes one module per *registered* route, and `SOCIAL_PRESENCE_ENABLED`
// decides whether the social routes are registered at all (config/social.php,
// routes/web.php). Generating with it off deletes `@/routes/today`,
// `@/routes/engage` and `@/routes/threads`, and the build then fails on the
// components that import them.
//
// So the helpers are always generated as though the feature were on. They are a
// type-level description of every route this application can serve; whether a
// particular deployment serves them is decided when it boots, not when its
// assets are compiled. What the sidebar and the settings screen actually show
// is driven by the `social.enabled` prop instead. The Docker build does the
// same thing in its own way — see the wayfinder stage in the Dockerfile.
process.env.SOCIAL_PRESENCE_ENABLED = 'true';

// A minified stack trace names `t` in chunk-4f2a.js at column 91827, which
// tells you nothing — so source maps are the whole point of reporting browser
// errors at all. But they are also a full copy of our frontend source, and one
// left sitting in public/build is that source published.
//
// Gating both halves on the token resolves that: maps are generated only when
// there is somewhere to send them, uploaded, and then deleted before the build
// finishes, so they are never among the files the server can serve. Without a
// token — a developer's machine, `composer setup`, CI — no map is produced and
// the build is byte-for-byte what it was before Sentry existed.
const uploadSourceMaps = Boolean(process.env.SENTRY_AUTH_TOKEN);

export default defineConfig({
    // Hidden rather than `true`: the map is emitted for upload but no
    // `//# sourceMappingURL=` comment is appended to the bundle, so a browser
    // never goes looking for a file we are about to delete.
    build: {
        sourcemap: uploadSourceMaps ? 'hidden' : false,
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        ...(skipWayfinder ? [] : [wayfinder({ formVariants: true })]),

        // Last, so it sees the finished bundle and the maps every earlier
        // plugin contributed to.
        ...(uploadSourceMaps
            ? [
                  sentryVitePlugin({
                      org: process.env.SENTRY_ORG,
                      project: process.env.SENTRY_PROJECT,
                      authToken: process.env.SENTRY_AUTH_TOKEN,
                      // Must match the server's SENTRY_RELEASE and the
                      // VITE_SENTRY_RELEASE compiled into the bundle. Sentry
                      // matches an event to its maps by release name, so three
                      // values that disagree produce uploaded maps that are
                      // never applied — which looks exactly like maps that
                      // failed to upload.
                      release: { name: process.env.SENTRY_RELEASE },
                      sourcemaps: {
                          filesToDeleteAfterUpload: [
                              'public/build/**/*.map',
                          ],
                      },
                  }),
              ]
            : []),
    ],
});
