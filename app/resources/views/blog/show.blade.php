@extends('blog.layout', ['lang' => $post->locale, 'ogTitle' => $post->title])

@push('head')
    <meta property="og:type" content="article">
    <meta property="og:locale" content="{{ str_replace('-', '_', $post->locale) }}">
    @if ($hero)
        <meta property="og:image" content="{{ $hero['url'] }}">
        @if (is_int($hero['width'] ?? null) && is_int($hero['height'] ?? null))
            <meta property="og:image:width" content="{{ $hero['width'] }}">
            <meta property="og:image:height" content="{{ $hero['height'] }}">
        @endif
        @if (is_string($hero['alt'] ?? null) && $hero['alt'] !== '')
            <meta property="og:image:alt" content="{{ $hero['alt'] }}">
        @endif
        <meta name="twitter:image" content="{{ $hero['url'] }}">
    @else
        <meta property="og:image" content="{{ $siteImage }}">
        <meta property="og:image:width" content="1731">
        <meta property="og:image:height" content="909">
        <meta name="twitter:image" content="{{ $siteImage }}">
    @endif
    <meta property="article:published_time" content="{{ $post->published_at->toAtomString() }}">
    <meta property="article:modified_time" content="{{ $post->updated_at->toAtomString() }}">
    @if (is_string($post->author['name'] ?? null))
        <meta name="author" content="{{ $post->author['name'] }}">
    @endif
    @foreach ($hreflang as $alternate)
        <link rel="alternate" hreflang="{{ $alternate['hreflang'] }}" href="{{ $alternate['url'] }}">
    @endforeach
    @foreach ($jsonLd as $block)
        <script type="application/ld+json">{!! $block !!}</script>
    @endforeach
@endpush

@section('content')
    {{-- The page is in the article's language; the few words of interface
         around it are English, and say so, so a screen reader does not read
         "All articles" with a German accent. --}}
    <article class="pb-24 sm:pb-32">
        <header class="mx-auto max-w-[46rem] px-5 pt-10 sm:px-8 sm:pt-16">
            <a href="{{ route('blog.index', absolute: false) }}" lang="en" class="inline-flex min-h-11 items-center gap-2 text-sm font-medium text-[#625d57] hover:text-[#17352f] hover:underline">
                <span aria-hidden="true">←</span> All articles
            </a>

            <h1 class="mt-6 text-[clamp(2.25rem,5vw,3.5rem)] leading-[1.08] font-semibold tracking-[-0.05em] text-balance">
                {{ $post->title }}
            </h1>

            @if ($post->summary)
                <p class="mt-6 text-xl leading-8 text-pretty text-[#625d57]">{{ $post->summary }}</p>
            @endif

            <div class="mt-8 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-[#d8cebd] pt-5 text-sm text-[#71675b]">
                @if (is_string($post->author['name'] ?? null) && $post->author['name'] !== '')
                    <p>
                        <span class="font-medium text-[#17352f]">{{ $post->author['name'] }}</span>@if (is_string($post->author['title'] ?? null) && $post->author['title'] !== ''), {{ $post->author['title'] }}@endif
                    </p>
                    <span aria-hidden="true">·</span>
                @endif
                <time datetime="{{ $post->published_at->toAtomString() }}" class="tabular-nums">
                    {{ $post->published_at->locale($post->locale)->isoFormat('LL') }}
                </time>
            </div>

            @if ($others->isNotEmpty())
                <p class="mt-3 text-sm text-[#71675b]">
                    <span lang="en">Also in</span>
                    @foreach ($others as $other)
                        <a href="{{ $other->path() }}" hreflang="{{ $other->locale }}" lang="{{ $other->locale }}" class="font-medium text-[#17352f] underline decoration-[#d8cebd] underline-offset-4 hover:decoration-[#d6533c]">{{ $other->languageName() }}</a>@if (! $loop->last)<span aria-hidden="true">,</span>@endif
                    @endforeach
                </p>
            @endif
        </header>

        @if ($hero)
            <figure class="mx-auto mt-10 max-w-5xl px-5 sm:mt-14 sm:px-8">
                <img
                    src="{{ $hero['url'] }}"
                    alt="{{ is_string($hero['alt'] ?? null) ? $hero['alt'] : '' }}"
                    @if (is_int($hero['width'] ?? null) && is_int($hero['height'] ?? null)) width="{{ $hero['width'] }}" height="{{ $hero['height'] }}" @endif
                    fetchpriority="high"
                    decoding="async"
                    class="h-auto w-full rounded-3xl bg-[#e7dcc7] outline-1 -outline-offset-1 outline-black/5"
                >
            </figure>
        @endif

        {{-- The body is HTML the receiver rendered from the engine's markdown
             with raw HTML stripped (see the webhook receiver), so it is safe
             to print as is. The prose colours are the landing's, set through
             the typography plugin's own variables. --}}
        <div class="mx-auto mt-12 max-w-[46rem] px-5 sm:mt-16 sm:px-8">
            <div class="prose prose-lg max-w-none [--tw-prose-body:#3f3a35] [--tw-prose-bold:#17352f] [--tw-prose-bullets:#b9ad99] [--tw-prose-captions:#71675b] [--tw-prose-code:#17352f] [--tw-prose-counters:#71675b] [--tw-prose-headings:#17352f] [--tw-prose-hr:#d8cebd] [--tw-prose-lead:#625d57] [--tw-prose-links:#17352f] [--tw-prose-pre-bg:#17352f] [--tw-prose-pre-code:#f3ecdd] [--tw-prose-quote-borders:#d6533c] [--tw-prose-quotes:#17352f] [--tw-prose-td-borders:#e3daca] [--tw-prose-th-borders:#d8cebd] prose-headings:tracking-[-0.03em] prose-headings:text-balance prose-a:decoration-[#d6533c] prose-a:decoration-1 prose-a:underline-offset-4 hover:prose-a:decoration-2 prose-img:rounded-2xl">
                {!! $post->html !!}
            </div>
        </div>

        <footer class="mx-auto mt-20 max-w-[46rem] px-5 sm:px-8" lang="en">
            <aside aria-labelledby="about-avyo" class="rounded-3xl border border-[#d8cebd] bg-[#fffaf2] p-7 sm:p-9">
                <p class="text-xs font-semibold tracking-[0.15em] text-[#a13220] uppercase">Written by Avyo</p>
                <h2 id="about-avyo" class="mt-4 text-2xl leading-tight font-semibold tracking-[-0.04em] text-balance">
                    This article was researched, written and published by Avyo.
                </h2>
                <p class="mt-4 text-base leading-7 text-pretty text-[#625d57]">
                    Avyo does the same for businesses like yours: it finds the questions your customers ask and publishes useful answers on your website, on your schedule.
                </p>
                <div class="mt-7 flex flex-wrap items-center gap-x-6 gap-y-4">
                    <a href="{{ route('plans.start', absolute: false) }}" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-[#17352f] px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#285046]">
                        Get started <span aria-hidden="true">→</span>
                    </a>
                    <a href="{{ route('home', absolute: false) }}#how-it-works" class="text-sm font-medium underline decoration-[#b9b5a7] underline-offset-4 hover:decoration-[#17352f]">
                        See how it works
                    </a>
                </div>
            </aside>

            <a href="{{ route('blog.index', absolute: false) }}" class="mt-10 inline-flex min-h-11 items-center gap-2 text-sm font-medium text-[#625d57] hover:text-[#17352f] hover:underline">
                <span aria-hidden="true">←</span> All articles
            </a>
        </footer>
    </article>
@endsection
