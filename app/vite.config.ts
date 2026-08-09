import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
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

export default defineConfig({
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
    ],
});
