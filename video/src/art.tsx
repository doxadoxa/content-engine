import { progress } from './anim';

/** The calendar thumbnails from the marketing page's CalendarPreview. */
export function SproutArt({ variant, width = 110 }: { variant: number; width?: number }) {
    return (
        <svg viewBox="0 0 100 60" width={width} fill="none">
            {variant === 0 ? (
                <>
                    <path d="M30 49V27a20 20 0 0 1 40 0v22" stroke="#ba6550" strokeWidth="8" />
                    <path d="M27 50h48" stroke="#17352f" strokeWidth="3" />
                    <circle cx="50" cy="31" r="8" fill="#e5ae61" />
                </>
            ) : variant === 1 ? (
                <>
                    <path d="M50 49V19" stroke="#17352f" strokeWidth="3" />
                    <path d="M50 34C29 35 23 19 29 13c16-1 22 11 21 21Z" fill="#708869" />
                    <path d="M50 25C51 10 65 6 71 10c0 13-9 19-21 15Z" fill="#17352f" />
                    <path d="M39 42h23l-5 13H44l-5-13Z" fill="#ba6550" />
                </>
            ) : (
                <>
                    <rect x="27" y="11" width="46" height="39" rx="5" fill="#fffdf8" />
                    <path d="M27 23h46M41 7v8m18-8v8" stroke="#3155a5" strokeWidth="3" />
                    <path d="m40 37 7 6 14-14" stroke="#64826b" strokeWidth="4" />
                </>
            )}
        </svg>
    );
}

/**
 * The ArticlePreview room, split into four groups that land one by one:
 * floor and window, the sofa, its cushions, the plant.
 */
export function RoomArt({ frame, at }: { frame: number; at: number[] }) {
    const g = (i: number) => {
        const p = progress(frame, at[i], 14);
        return { opacity: p, transform: `translateY(${(1 - p) * 24}px)`, transformBox: 'fill-box' as const };
    };
    return (
        <svg viewBox="0 0 360 200" width="100%" height="100%" fill="none" preserveAspectRatio="xMidYMid slice">
            <g style={g(0)}>
                <path d="M0 165h360v35H0z" fill="#c8d0ba" />
                <path d="M42 156V62a45 45 0 0 1 90 0v94" fill="#fbf2d9" />
                <path d="M87 18v138M42 80h90" stroke="#cfb992" strokeWidth="5" />
                <path d="M70 159 130 82v83H70Z" fill="#eed7a6" />
            </g>
            <g style={g(1)}>
                <rect x="143" y="104" width="142" height="50" rx="18" fill="#be674e" />
                <rect x="134" y="127" width="160" height="42" rx="13" fill="#cb7b60" />
                <path d="M149 165v13m130-13v13" stroke="#704333" strokeWidth="5" />
            </g>
            <g style={g(2)}>
                <rect x="158" y="111" width="47" height="30" rx="8" fill="#ead1ae" />
                <rect x="219" y="111" width="47" height="30" rx="8" fill="#d9ad85" />
            </g>
            <g style={g(3)}>
                <path d="M321 156V76" stroke="#42634c" strokeWidth="4" />
                <path d="M321 124c-30 0-36-22-31-30 22 0 33 11 31 30Z" fill="#719074" />
                <path d="M321 103c0-28 20-42 28-38 5 25-11 39-28 38Z" fill="#42634c" />
                <path d="m302 144 8 33h26l7-33h-41Z" fill="#af8c61" />
            </g>
        </svg>
    );
}
