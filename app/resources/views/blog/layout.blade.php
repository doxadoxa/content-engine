{{--
    The blog's page frame: server-rendered, stylesheet only, no React.

    It echoes the marketing landing (resources/js/pages/marketing.tsx) — the
    same paper, forest ink and header — but is Blade, because the blog is read
    by crawlers and AI tools that never run JavaScript, and everything they
    need has to be in this HTML.

    Always light, like the marketing and legal pages: `.marketing-page` pins
    the brand tokens, and nothing here ever puts `.dark` on <html>. Expects
    $title, $description and $canonical; views push anything else into `head`.
--}}
<!DOCTYPE html>
<html lang="{{ $lang ?? config('blog.locale', 'en') }}" class="bg-[#f8f2e8]">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#f8f2e8">
        <meta name="color-scheme" content="light">

        <title>{{ $title }}</title>
        <meta name="description" content="{{ $description }}">
        <link rel="canonical" href="{{ $canonical }}">
        <link rel="alternate" type="application/rss+xml" title="Avyo Blog" href="{{ rtrim((string) config('app.url'), '/') }}/blog/feed.xml">

        <meta property="og:site_name" content="Avyo">
        <meta property="og:title" content="{{ $ogTitle ?? $title }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ $canonical }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $ogTitle ?? $title }}">
        <meta name="twitter:description" content="{{ $description }}">
        @stack('head')

        {{-- Same mtime stamp as app.blade.php, for the same reason: a favicon
             is the one asset a browser will not re-fetch on a hard reload. --}}
        @php($iconVersion = @filemtime(public_path('favicon.svg')) ?: 1)
        <link rel="icon" href="/favicon.svg?v={{ $iconVersion }}" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico?v={{ $iconVersion }}" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v={{ $iconVersion }}">

        @vite(['resources/css/app.css'])
    </head>
    {{-- One focus ring for every link and button on the page, in the ink the
         landing uses for its own, rather than a class on each of them. --}}
    <body class="marketing-page flex min-h-screen flex-col bg-[#f8f2e8] font-sans text-[#17352f] antialiased [&_:focus-visible]:rounded-sm [&_:focus-visible]:outline-2 [&_:focus-visible]:outline-offset-4 [&_:focus-visible]:outline-[#17352f]">
        <a href="#content" lang="en" class="sr-only rounded-full bg-[#17352f] px-4 py-2 text-sm font-semibold text-white focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-10">
            Skip to content
        </a>

        <header lang="en" class="border-b border-[#d8cebd]">
            <div class="mx-auto flex min-h-20 max-w-6xl items-center justify-between gap-5 px-5 sm:px-8">
                <a href="{{ route('home', absolute: false) }}" aria-label="Avyo home" class="inline-flex items-center gap-2.5">
                    <img src="/favicon.svg?v={{ $iconVersion }}" alt="" width="32" height="32" class="size-8">
                    <span class="text-xl font-semibold tracking-[-0.04em]">Avyo</span>
                </a>
                <nav aria-label="Primary navigation" class="flex items-center gap-5 text-sm sm:gap-6">
                    <a href="{{ route('home', absolute: false) }}#how-it-works" class="hidden hover:underline md:inline">How it works</a>
                    <a href="{{ route('home', absolute: false) }}#pricing" class="hidden hover:underline md:inline">Pricing</a>
                    <a href="{{ route('blog.index', absolute: false) }}" aria-current="{{ request()->routeIs('blog.index') ? 'page' : 'true' }}" class="font-medium underline decoration-[#d6533c] decoration-2 underline-offset-8">Blog</a>
                    <a href="{{ route('login', absolute: false) }}" class="rounded-full border border-[#b9b5a7] px-5 py-2.5 font-medium hover:bg-white/60">Log in</a>
                </nav>
            </div>
        </header>

        <main id="content" class="flex-1">
            @yield('content')
        </main>

        <footer lang="en" class="mx-auto w-full max-w-6xl px-5 py-8 sm:px-8">
            <div class="flex flex-wrap items-center justify-between gap-6">
                <a href="{{ route('home', absolute: false) }}" aria-label="Avyo home" class="inline-flex items-center gap-2.5">
                    <img src="/favicon.svg?v={{ $iconVersion }}" alt="" width="32" height="32" class="size-8">
                    <span class="text-xl font-semibold tracking-[-0.04em]">Avyo</span>
                </a>
                <nav aria-label="Footer" class="flex flex-wrap gap-5 text-xs text-[#625d57]">
                    <a href="{{ route('blog.index', absolute: false) }}" class="hover:underline">Blog</a>
                    <a href="{{ route('blog.feed', absolute: false) }}" class="hover:underline">RSS</a>
                    <a href="{{ route('legal.privacy', absolute: false) }}" class="hover:underline">Privacy</a>
                    <a href="{{ route('legal.terms', absolute: false) }}" class="hover:underline">Terms</a>
                    <a href="{{ route('legal.cookies', absolute: false) }}" class="hover:underline">Cookies</a>
                </nav>
            </div>
            <p class="mt-6 border-t border-[#d8cebd] pt-5 text-xs leading-5 text-[#71675b]">
                © {{ now()->year }} Avyo · {{ config('legal.entity') }}, registered in {{ config('legal.jurisdiction') }}, company number {{ config('legal.company_number') }}
            </p>
        </footer>
    </body>
</html>
