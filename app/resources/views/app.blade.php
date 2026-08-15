<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#151419">
        <meta name="description" content="Avyo turns brand strategy into search content, social posts, and measurable organic growth.">
        <meta property="og:site_name" content="Avyo">
        <meta property="og:type" content="website">
        <meta property="og:title" content="Avyo — One engine for staying visible">
        <meta property="og:description" content="Turn brand strategy into search content, social posts, and measurable organic growth.">
        <meta property="og:image" content="{{ url('/og.png') }}">
        <meta property="og:image:width" content="1731">
        <meta property="og:image:height" content="909">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="Avyo — One engine for staying visible">
        <meta name="twitter:description" content="Turn brand strategy into search content, social posts, and measurable organic growth.">
        <meta name="twitter:image" content="{{ url('/og.png') }}">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ app(\Illuminate\Foundation\Vite::class)->cspNonce() }}">
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style nonce="{{ app(\Illuminate\Foundation\Vite::class)->cspNonce() }}">
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{-- The SVG is what every current browser uses; the .ico is the
             fallback older ones fetch from the root whether or not it is
             declared, so it is declared and real rather than the empty file it
             used to be.

             Stamped with the file's own mtime, because a favicon is the one
             asset a browser will not re-fetch on a hard reload — Chrome keeps
             it in a separate store and honours the week-long max-age against
             it. Without a changing URL, everyone who has already loaded the app
             keeps the old mark for seven days and nobody can clear it from our
             side. --}}
        @php($iconVersion = @filemtime(public_path('favicon.svg')) ?: 1)
        <link rel="icon" href="/favicon.svg?v={{ $iconVersion }}" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico?v={{ $iconVersion }}" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v={{ $iconVersion }}">


        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Avyo') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
