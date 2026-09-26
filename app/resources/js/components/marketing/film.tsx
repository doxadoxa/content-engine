import { Link } from '@inertiajs/react';
import { ArrowRight, Play, RotateCcw, Volume2 } from 'lucide-react';
import type { MouseEvent } from 'react';
import { useRef, useState } from 'react';
import poster from '../../../media/avyo-film-poster.jpg';
import film from '../../../media/avyo-film.mp4';

/*
 * The homepage film. Its source is the Remotion project in /video at the
 * repository root; `npm run render && npm run poster && npm run publish` there
 * writes both files into resources/media.
 */

const FILM_ID = 'avyo-film';

/** The words the film puts on screen, in order: it has music but no narration. */
const transcript = [
    'what’s included in a deep clean?',
    'Every day, people ask Google and AI about what you do.',
    'When they ask, will they find you?',
    'Avyo. Get found. Win more customers.',
    '01 Research. Avyo finds the questions your customers ask.',
    '02 Write. Helpful answers, in your voice.',
    '03 Publish. Published on your site. Automatically, or after your review.',
    '04 Measure. See who finds you. Learn what leads to sales.',
    'Help your next customer find you. Research, writing and publishing, handled.',
];

/**
 * For the hero's "Watch the film" link: bring the film into view and start it
 * inside the same click. Browsers only allow playback with sound from a user
 * gesture, so starting it here rather than after the scroll is what lets the
 * music play.
 */
export function playFilm(event: MouseEvent<HTMLAnchorElement>) {
    const video = document.getElementById(FILM_ID);

    if (!(video instanceof HTMLVideoElement)) {
        return;
    }

    event.preventDefault();
    const reduce = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;
    video.scrollIntoView({
        behavior: reduce ? 'auto' : 'smooth',
        block: 'center',
    });
    void video.play().catch(() => video.setAttribute('controls', ''));
}

export function FilmSection() {
    const video = useRef<HTMLVideoElement>(null);
    const [state, setState] = useState<'idle' | 'playing' | 'ended'>('idle');

    const play = () => {
        const element = video.current;

        if (!element) {
            return;
        }

        element.currentTime = state === 'ended' ? 0 : element.currentTime;
        // If playback is refused, fall back to the browser's own controls
        // rather than leaving a play button that does nothing.
        void element.play().catch(() => setState('playing'));
    };

    return (
        <section
            id="film"
            aria-labelledby="film-title"
            className="mx-auto max-w-6xl scroll-mt-8 px-5 pb-24 sm:px-8 sm:pb-32"
        >
            <div className="flex flex-wrap items-end justify-between gap-x-10 gap-y-5 border-t border-[#ded6c7] pt-14 sm:pt-20">
                <div>
                    <p className="text-xs font-semibold tracking-[0.15em] text-[#a13220] uppercase">
                        Avyo in under a minute
                    </p>
                    <h2
                        id="film-title"
                        className="mt-5 text-4xl leading-[1.12] font-semibold tracking-[-0.045em] sm:text-5xl"
                    >
                        From their question
                        <br />
                        <span className="font-serif font-normal italic">
                            to your business.
                        </span>
                    </h2>
                </div>
                <p className="max-w-xs text-sm leading-7 text-[#625d57]">
                    See how a question someone asks Google or AI becomes an
                    article on your website and a visit you can measure.
                </p>
            </div>
            <figure className="relative mt-12">
                <div
                    className="absolute -top-8 -right-4 size-44 rounded-full bg-[#e8ba64]/35 sm:-right-8"
                    aria-hidden="true"
                />
                <div
                    className="absolute -bottom-8 -left-6 size-52 rounded-full border-[28px] border-[#c85b43]/10"
                    aria-hidden="true"
                />
                <div className="relative aspect-video overflow-hidden rounded-[1.75rem] border border-[#17352f]/15 bg-[#17352f] shadow-[0_28px_70px_-28px_#17352f55]">
                    <video
                        ref={video}
                        id={FILM_ID}
                        className="size-full object-cover"
                        poster={poster}
                        preload="none"
                        playsInline
                        controls={state === 'playing'}
                        aria-label="Avyo product film, 55 seconds, with music"
                        aria-describedby="film-transcript"
                        onPlay={() => setState('playing')}
                        onEnded={() => setState('ended')}
                    >
                        <source src={film} type="video/mp4" />
                    </video>
                    {state === 'idle' && (
                        <button
                            type="button"
                            onClick={play}
                            className="group absolute inset-0 flex items-end justify-between gap-4 p-5 text-left text-[#fffaf0] focus-visible:outline-none sm:p-8"
                        >
                            <span className="inline-flex items-center gap-3 rounded-full bg-[#f8f2e8] py-2 pr-6 pl-2 text-[#17352f] shadow-[0_18px_40px_-12px_#0008] transition-transform group-hover:scale-[1.03] group-focus-visible:outline-2 group-focus-visible:outline-offset-4 group-focus-visible:outline-[#f3cf6a] motion-reduce:transition-none sm:gap-4 sm:py-2.5 sm:pr-7 sm:pl-2.5">
                                <span
                                    className="grid size-10 place-items-center rounded-full bg-[#17352f] text-[#f8f2e8] sm:size-14"
                                    aria-hidden="true"
                                >
                                    <Play className="ml-0.5 size-4 fill-current sm:size-5" />
                                </span>
                                <span className="text-sm font-semibold sm:text-base">
                                    Watch the film{' '}
                                    <span className="font-normal text-[#625d57]">
                                        · 0:55
                                    </span>
                                </span>
                            </span>
                            <span className="relative hidden items-center gap-2 rounded-full bg-[#17352f]/80 px-4 py-2 text-xs text-[#d3ddce] backdrop-blur-sm sm:flex">
                                <Volume2
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Sound on
                            </span>
                        </button>
                    )}
                    {state === 'ended' && (
                        <div className="absolute inset-0 flex flex-col items-center justify-center gap-5 bg-[#17352f]/75 p-6 text-center text-[#fffaf0] backdrop-blur-sm">
                            <p className="font-serif text-2xl sm:text-4xl">
                                Ready to be found?
                            </p>
                            <div className="flex flex-wrap items-center justify-center gap-3">
                                <Link
                                    href="/start"
                                    className="inline-flex min-h-12 items-center gap-2 rounded-full bg-[#f8f2e8] px-6 py-3 text-sm font-semibold text-[#17352f] transition-colors hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#f3cf6a]"
                                >
                                    Get started{' '}
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                                <button
                                    type="button"
                                    onClick={play}
                                    className="inline-flex min-h-12 items-center gap-2 rounded-full border border-white/30 px-5 py-3 text-sm font-medium hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#f3cf6a]"
                                >
                                    <RotateCcw
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Watch again
                                </button>
                            </div>
                        </div>
                    )}
                </div>
                <figcaption className="relative mt-6 text-center text-xs leading-5 text-[#746d60]">
                    Product film · Illustrative business and data · Music, no
                    narration
                    <details className="mx-auto mt-3 max-w-xl text-left">
                        <summary className="cursor-pointer text-center underline decoration-[#b9b5a7] underline-offset-4">
                            Read the words in the film
                        </summary>
                        <ol
                            id="film-transcript"
                            className="mt-4 list-inside list-decimal space-y-2 text-sm leading-6 text-[#625d57]"
                        >
                            {transcript.map((line) => (
                                <li key={line}>{line}</li>
                            ))}
                        </ol>
                    </details>
                </figcaption>
            </figure>
        </section>
    );
}
