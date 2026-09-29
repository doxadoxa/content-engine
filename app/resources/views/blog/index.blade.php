@extends('blog.layout')

@push('head')
    <meta property="og:type" content="website">
    <meta property="og:image" content="{{ $siteImage }}">
    <meta property="og:image:width" content="1731">
    <meta property="og:image:height" content="909">
    <meta name="twitter:image" content="{{ $siteImage }}">
    @if ($prev)
        <link rel="prev" href="{{ $prev }}">
    @endif
    @if ($next)
        <link rel="next" href="{{ $next }}">
    @endif
    @foreach ($jsonLd as $block)
        <script type="application/ld+json">{!! $block !!}</script>
    @endforeach
@endpush

@section('content')
    <div class="mx-auto max-w-6xl px-5 pt-16 pb-24 sm:px-8 sm:pt-24 sm:pb-32">
        <header class="max-w-2xl">
            <p class="text-xs font-semibold tracking-[0.15em] text-[#a13220] uppercase">Avyo Blog</p>
            <h1 class="mt-5 text-[clamp(2.5rem,5vw,4rem)] leading-[1.05] font-semibold tracking-[-0.055em] text-balance">
                Getting found,
                <span class="font-serif font-normal text-[#bc452f] italic">written down.</span>
            </h1>
            <p class="mt-6 max-w-xl text-lg leading-8 text-pretty text-[#625d57]">
                Practical guides to reaching customers through search and AI answers — researched, written and published by Avyo itself.
            </p>
            @if ($posts->currentPage() > 1)
                <p class="mt-4 text-sm text-[#71675b]">Page {{ $posts->currentPage() }} of {{ $posts->lastPage() }}</p>
            @endif
        </header>

        @if ($posts->isEmpty())
            <section class="mt-16 max-w-2xl rounded-3xl border border-[#d8cebd] bg-[#fffaf2] p-8 sm:p-10">
                <h2 class="text-xl font-semibold tracking-[-0.03em]">Nothing published yet</h2>
                <p class="mt-3 text-base leading-7 text-[#625d57]">
                    The first articles are on their way. Until then, the landing page shows what Avyo does and how it works.
                </p>
                <a href="{{ route('home', absolute: false) }}#how-it-works" class="mt-6 inline-flex items-center gap-2 text-sm font-semibold underline decoration-[#d6533c] decoration-2 underline-offset-4">
                    See how Avyo works <span aria-hidden="true">→</span>
                </a>
            </section>
        @else
            <h2 class="sr-only">Articles</h2>
            <ol role="list" class="mt-14 grid gap-x-8 gap-y-14 sm:mt-20 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
                    @php($hero = $post->hero())
                    <li>
                        {{-- The title link is stretched over the card, so the whole card is one target and one link for a screen reader rather than three. --}}
                        <article class="group relative flex h-full flex-col">
                            <div class="aspect-[16/9] overflow-hidden rounded-2xl bg-[#e7dcc7] outline-1 -outline-offset-1 outline-black/5">
                                @if ($hero)
                                    <img
                                        src="{{ $hero['url'] }}"
                                        alt=""
                                        @if (is_int($hero['width'] ?? null) && is_int($hero['height'] ?? null)) width="{{ $hero['width'] }}" height="{{ $hero['height'] }}" @endif
                                        loading="{{ $loop->index < 3 ? 'eager' : 'lazy' }}"
                                        decoding="async"
                                        class="size-full object-cover transition-transform duration-500 group-hover:scale-[1.02]"
                                    >
                                @else
                                    <div class="grid size-full place-items-center" aria-hidden="true">
                                        <img src="/favicon.svg" alt="" width="40" height="40" class="size-10 opacity-80">
                                    </div>
                                @endif
                            </div>
                            <time datetime="{{ $post->published_at->toAtomString() }}" class="mt-5 text-xs font-medium tracking-wide text-[#71675b] tabular-nums">
                                {{ $post->published_at->locale($post->locale)->isoFormat('LL') }}
                            </time>
                            <h3 class="mt-2 text-xl leading-snug font-semibold tracking-[-0.03em] text-balance">
                                <a href="{{ $post->path() }}" class="after:absolute after:inset-0 after:rounded-2xl group-hover:underline group-hover:decoration-[#d6533c] group-hover:decoration-2 group-hover:underline-offset-4">
                                    {{ $post->title }}
                                </a>
                            </h3>
                            @if ($post->summary)
                                <p class="mt-3 line-clamp-3 text-[15px] leading-7 text-pretty text-[#625d57]">{{ $post->summary }}</p>
                            @endif
                        </article>
                    </li>
                @endforeach
            </ol>

            @if ($posts->hasPages())
                <nav aria-label="Pagination" class="mt-20 flex items-center justify-between gap-4 border-t border-[#d8cebd] pt-6 text-sm">
                    @if ($posts->onFirstPage())
                        <span aria-hidden="true"></span>
                    @else
                        {{-- Page one is /blog, never /blog?page=1: one address per page. --}}
                        <a href="{{ $posts->currentPage() === 2 ? $posts->path() : $posts->previousPageUrl() }}" rel="prev" class="inline-flex min-h-11 items-center gap-2 font-medium hover:underline">
                            <span aria-hidden="true">←</span> Newer articles
                        </a>
                    @endif
                    <span class="text-[#71675b] tabular-nums">Page {{ $posts->currentPage() }} of {{ $posts->lastPage() }}</span>
                    @if ($posts->hasMorePages())
                        <a href="{{ $posts->nextPageUrl() }}" rel="next" class="inline-flex min-h-11 items-center gap-2 font-medium hover:underline">
                            Older articles <span aria-hidden="true">→</span>
                        </a>
                    @else
                        <span aria-hidden="true"></span>
                    @endif
                </nav>
            @endif
        @endif
    </div>
@endsection
