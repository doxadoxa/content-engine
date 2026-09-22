import {
    ArrowUpRight,
    CalendarDays,
    Check,
    CheckCheck,
    ChevronRight,
    Globe2,
    Search,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';

const articles = [
    {
        day: 'MON',
        date: '14',
        title: 'A calmer home starts here',
        category: 'Everyday care',
        color: 'bg-[#f2d9d0]',
    },
    {
        day: 'WED',
        date: '16',
        title: 'What to expect from a deep clean',
        category: 'Your services',
        color: 'bg-[#e3e9da]',
    },
    {
        day: 'FRI',
        date: '18',
        title: 'Getting ready for your first visit',
        category: 'Customer questions',
        color: 'bg-[#e1e7f4]',
    },
];

/** An explicitly illustrative preview; its controls never change a project. */
export function CalendarPreview() {
    const [review, setReview] = useState(false);
    const [selected, setSelected] = useState(1);

    return (
        <figure className="relative min-w-0">
            <div
                className="absolute -top-7 -right-3 size-40 rounded-full bg-[#e8ba64]/35 sm:-right-6"
                aria-hidden="true"
            />
            <div
                className="absolute -bottom-7 -left-5 size-44 rounded-full border-[24px] border-[#c85b43]/10"
                aria-hidden="true"
            />
            <div className="relative overflow-hidden rounded-[1.75rem] border border-[#17352f]/15 bg-[#fffdf8] shadow-[0_28px_70px_-28px_#17352f55]">
                <div className="flex items-center justify-between gap-3 border-b border-[#e9e2d7] px-5 py-4">
                    <span className="flex items-center gap-2 text-sm font-semibold">
                        <span className="size-2 rounded-full bg-[#bb5137]" />{' '}
                        Avyo
                    </span>
                    <span className="text-[11px] text-[#726f64]">
                        Example business · Home &amp; Co.
                    </span>
                </div>
                <div className="p-5 sm:p-7">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <p className="text-[10px] font-semibold tracking-widest text-[#726f64] uppercase">
                                Your content calendar
                            </p>
                            <p className="mt-2 text-2xl font-semibold tracking-tight">
                                A good week ahead.
                            </p>
                        </div>
                        <CalendarDays
                            className="size-6 shrink-0 text-[#64826b]"
                            aria-hidden="true"
                        />
                    </div>
                    <div
                        className="mt-6 flex w-fit flex-wrap gap-1 rounded-xl bg-[#f0eee5] p-1"
                        role="group"
                        aria-label="Preview publishing mode"
                    >
                        {['Automatic', 'Review first'].map((label, index) => (
                            <button
                                key={label}
                                type="button"
                                aria-pressed={review === Boolean(index)}
                                onClick={() => setReview(Boolean(index))}
                                className={`min-h-10 rounded-lg px-3 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#17352f] ${review === Boolean(index) ? 'bg-white text-[#17352f] shadow-sm' : 'text-[#696b60] hover:bg-white/60'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="mt-6 grid grid-cols-3 gap-2.5 sm:gap-3">
                        {articles.map((article, index) => (
                            <button
                                key={article.day}
                                type="button"
                                onClick={() => setSelected(index)}
                                aria-pressed={selected === index}
                                aria-label={`Preview article: ${article.title}`}
                                className={`min-w-0 rounded-xl border p-3 text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#17352f] ${selected === index ? 'border-[#64826b] bg-[#f4f6ee]' : 'border-[#e9e2d7] bg-white hover:bg-[#faf8f2]'}`}
                            >
                                <span className="block text-[10px] font-medium text-[#726f64]">
                                    {article.day}
                                </span>
                                <span className="mt-1 block text-xl font-semibold">
                                    {article.date}
                                </span>
                                <span
                                    className={`mt-4 flex h-14 items-center justify-center rounded-lg ${article.color}`}
                                >
                                    <SproutArt variant={index} />
                                </span>
                                <span className="mt-3 block text-xs leading-5 font-medium">
                                    {article.title}
                                </span>
                                <span className="mt-3 block text-[10px] text-[#586b54]">
                                    {review ? 'Awaiting review' : 'Scheduled'}
                                </span>
                            </button>
                        ))}
                    </div>
                    <div
                        className="mt-5 flex items-start gap-3 rounded-xl bg-[#17352f] p-4 text-[#fffaf0]"
                        aria-live="polite"
                        aria-atomic="true"
                    >
                        {review ? (
                            <CheckCheck
                                className="mt-0.5 size-5 shrink-0 text-[#edcc75]"
                                aria-hidden="true"
                            />
                        ) : (
                            <Sparkles
                                className="mt-0.5 size-5 shrink-0 text-[#edcc75]"
                                aria-hidden="true"
                            />
                        )}
                        <div>
                            <p className="text-xs font-medium">
                                {articles[selected].title}
                            </p>
                            <p className="mt-1 text-[11px] leading-5 text-[#d3ddce]">
                                {review
                                    ? 'Ready for your review before it goes live.'
                                    : 'Ready to publish on your website at 09:00.'}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <figcaption className="relative mt-6 text-center text-xs text-[#746d60]">
                Interactive preview · Try a topic or publishing mode
            </figcaption>
        </figure>
    );
}

function SproutArt({ variant = 0 }: { variant?: number }) {
    return (
        <svg
            viewBox="0 0 100 60"
            className="h-full w-20"
            fill="none"
            aria-hidden="true"
        >
            {variant === 0 ? (
                <>
                    <path
                        d="M30 49V27a20 20 0 0 1 40 0v22"
                        stroke="#ba6550"
                        strokeWidth="8"
                    />
                    <path d="M27 50h48" stroke="#17352f" strokeWidth="3" />
                    <circle cx="50" cy="31" r="8" fill="#e5ae61" />
                </>
            ) : variant === 1 ? (
                <>
                    <path d="M50 49V19" stroke="#17352f" strokeWidth="3" />
                    <path
                        d="M50 34C29 35 23 19 29 13c16-1 22 11 21 21Z"
                        fill="#708869"
                    />
                    <path
                        d="M50 25C51 10 65 6 71 10c0 13-9 19-21 15Z"
                        fill="#17352f"
                    />
                    <path d="M39 42h23l-5 13H44l-5-13Z" fill="#ba6550" />
                </>
            ) : (
                <>
                    <rect
                        x="27"
                        y="11"
                        width="46"
                        height="39"
                        rx="5"
                        fill="#fffdf8"
                    />
                    <path
                        d="M27 23h46M41 7v8m18-8v8"
                        stroke="#3155a5"
                        strokeWidth="3"
                    />
                    <path
                        d="m40 37 7 6 14-14"
                        stroke="#64826b"
                        strokeWidth="4"
                    />
                </>
            )}
        </svg>
    );
}

export function ArticlePreview() {
    return (
        <figure className="relative rounded-[2rem] bg-[#e9e5d9] p-6 sm:p-10">
            <div className="mx-auto max-w-sm rotate-[-2deg] overflow-hidden rounded-xl border border-[#d9d3c6] bg-[#fffdf8] shadow-[0_16px_40px_-20px_#17352f55]">
                <div className="flex items-center justify-between border-b border-[#e9e2d7] px-5 py-3 text-[10px] text-[#746d60]">
                    <span>HOME &amp; CO. / JOURNAL</span>
                    <span>Article preview</span>
                </div>
                <div
                    className="relative flex h-44 items-center justify-center overflow-hidden bg-[#e2e7d9] sm:h-52"
                    aria-hidden="true"
                >
                    <svg
                        viewBox="0 0 360 200"
                        className="h-full w-full"
                        fill="none"
                    >
                        <path d="M0 165h360v35H0z" fill="#c8d0ba" />
                        <path
                            d="M42 156V62a45 45 0 0 1 90 0v94"
                            fill="#fbf2d9"
                        />
                        <path
                            d="M87 18v138M42 80h90"
                            stroke="#cfb992"
                            strokeWidth="5"
                        />
                        <path d="M70 159 130 82v83H70Z" fill="#eed7a6" />
                        <rect
                            x="143"
                            y="104"
                            width="142"
                            height="50"
                            rx="18"
                            fill="#be674e"
                        />
                        <rect
                            x="134"
                            y="127"
                            width="160"
                            height="42"
                            rx="13"
                            fill="#cb7b60"
                        />
                        <rect
                            x="158"
                            y="111"
                            width="47"
                            height="30"
                            rx="8"
                            fill="#ead1ae"
                        />
                        <rect
                            x="219"
                            y="111"
                            width="47"
                            height="30"
                            rx="8"
                            fill="#d9ad85"
                        />
                        <path
                            d="M149 165v13m130-13v13"
                            stroke="#704333"
                            strokeWidth="5"
                        />
                        <path
                            d="M321 156V76"
                            stroke="#42634c"
                            strokeWidth="4"
                        />
                        <path
                            d="M321 124c-30 0-36-22-31-30 22 0 33 11 31 30Z"
                            fill="#719074"
                        />
                        <path
                            d="M321 103c0-28 20-42 28-38 5 25-11 39-28 38Z"
                            fill="#42634c"
                        />
                        <path d="m302 144 8 33h26l7-33h-41Z" fill="#af8c61" />
                    </svg>
                </div>
                <div className="px-6 py-7">
                    <p className="text-[10px] font-medium tracking-wider text-[#a14f39] uppercase">
                        A little know-how goes a long way
                    </p>
                    <p className="mt-3 font-serif text-3xl leading-tight">
                        What to expect from a deep clean
                    </p>
                    <p className="mt-4 text-xs leading-6 text-[#6c6f63]">
                        A practical guide to preparing your home, choosing the
                        right service, and making the most of your first visit.
                    </p>
                    <div className="mt-5 flex items-center gap-2 text-[10px] text-[#63795d]">
                        <Check className="size-3" aria-hidden="true" /> Based on
                        your business brief
                    </div>
                </div>
            </div>
            <figcaption className="mt-7 text-center text-xs text-[#746d60]">
                Illustrative article · Your business, your voice
            </figcaption>
        </figure>
    );
}

export function DiscoveryPreview() {
    return (
        <figure className="rounded-[2rem] bg-[#e6eaf2] p-6 sm:p-10">
            <div className="rounded-2xl border border-[#ccd3e0] bg-[#f9faff] p-5 shadow-sm sm:p-6">
                <div className="flex items-center gap-3 rounded-full border border-[#d9dfeb] bg-white px-4 py-3 text-xs text-[#59687b]">
                    <Search className="size-4 shrink-0" aria-hidden="true" />{' '}
                    What’s included in a professional deep clean?
                </div>
                <div className="mt-6 border-l-2 border-[#849bcd] pl-4">
                    <p className="flex items-center gap-2 text-[10px] text-[#687889]">
                        <Globe2 className="size-3" aria-hidden="true" /> YOUR
                        WEBSITE / JOURNAL
                    </p>
                    <p className="mt-2 text-lg font-medium text-[#3155a5]">
                        Your business, at the right moment.
                    </p>
                    <div className="mt-3 h-1.5 w-full rounded-full bg-[#dde2ed]" />
                    <div className="mt-2 h-1.5 w-3/4 rounded-full bg-[#dde2ed]" />
                </div>
            </div>
            <div className="mx-auto h-8 w-px bg-[#b7c1d4]" aria-hidden="true" />
            <div className="mx-auto max-w-xs rounded-2xl bg-[#17352f] p-5 text-[#fffaf0]">
                <p className="flex items-center gap-2 text-xs font-medium">
                    <Sparkles
                        className="size-4 text-[#edcc75]"
                        aria-hidden="true"
                    />{' '}
                    Your growth, in view
                </p>
                <div className="mt-5 flex items-center justify-between text-xs text-[#d3ddce]">
                    <span>Search clicks</span>
                    <ArrowUpRight className="size-4" aria-hidden="true" />
                </div>
                <div className="mt-3 flex items-center justify-between text-xs text-[#d3ddce]">
                    <span>AI mentions &amp; citations</span>
                    <ChevronRight className="size-4" aria-hidden="true" />
                </div>
                <div className="mt-3 flex items-center justify-between gap-3 text-xs text-[#d3ddce]">
                    <span>Purchases, when connected</span>
                    <ChevronRight
                        className="size-4 shrink-0"
                        aria-hidden="true"
                    />
                </div>
            </div>
            <figcaption className="mt-7 text-center text-xs leading-5 text-[#637083]">
                Illustrative search appearance · Track real observations in your
                dashboard
            </figcaption>
        </figure>
    );
}
