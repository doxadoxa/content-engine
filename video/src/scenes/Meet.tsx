import { AbsoluteFill, interpolate, useCurrentFrame } from 'remotion';
import { easeInOut, Line, progress, useSpring } from '../anim';
import { Logo } from '../Logo';
import { C, SANS, SERIF } from '../theme';
import T from '../timeline.json';

const M = T.meet;

/**
 * Forest opens out of the bottom-right corner — the same corner the mark's
 * circular bite is taken from — and the mark assembles in three moves.
 */
export function Meet() {
    const frame = useCurrentFrame();
    const [start, end] = T.scenes.meet;
    const wipe = progress(frame, M.wipe, 26, easeInOut);
    const radius = wipe * 2300;
    const tile = useSpring(M.tile, 12);
    const quarter = useSpring(M.quarter, 16);
    const bite = progress(frame, M.bite, 12);
    const word = progress(frame, M.wordmark, 20);
    const drift = interpolate(frame, [start, end], [1.04, 1]);
    const exit = progress(frame, end - 14, 20, easeInOut);
    if (frame < start || frame >= end + 6) return null;

    return (
        <AbsoluteFill
            style={{
                background: C.ink,
                clipPath: `circle(${radius}px at 1920px 1080px)`,
                fontFamily: SANS,
                color: C.cream,
                alignItems: 'center',
                justifyContent: 'center',
                transform: `translateY(${-exit * 160}px)`,
            }}
        >
            <div
                style={{
                    position: 'absolute',
                    right: -260,
                    bottom: -260,
                    width: 900,
                    height: 900,
                    borderRadius: '50%',
                    border: '110px solid rgba(214,83,60,.13)',
                    transform: `scale(${0.6 + 0.4 * wipe})`,
                }}
            />
            <div style={{ transform: `scale(${drift})`, display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 56 }}>
                    <div style={{ transform: `rotate(${(1 - tile) * -12}deg)` }}>
                        <Logo size={230} tile={tile} quarter={quarter} bite={bite} tone="cream" />
                    </div>
                    <div style={{ overflow: 'hidden', width: 560 * word, marginLeft: (word - 1) * 56 }}>
                        <div
                            style={{
                                fontSize: 230,
                                fontWeight: 600,
                                letterSpacing: '-0.05em',
                                lineHeight: 1,
                                transform: `translateX(${(1 - word) * -120}px)`,
                            }}
                        >
                            Avyo
                        </div>
                    </div>
                </div>
                <div style={{ display: 'flex', gap: '0.28em', marginTop: 70, fontSize: 76, letterSpacing: '-0.045em' }}>
                    <Line at={M.tagline1}>
                        <span style={{ fontWeight: 600 }}>Get found.</span>
                    </Line>
                    <Line at={M.tagline2}>
                        <span
                            style={{
                                fontFamily: SERIF,
                                fontStyle: 'italic',
                                color: C.terracottaLight,
                                letterSpacing: '-0.02em',
                            }}
                        >
                            Win more customers.
                        </span>
                    </Line>
                </div>
            </div>
            {exit > 0 && <AbsoluteFill style={{ background: C.ink, opacity: 0.35 * exit }} />}
        </AbsoluteFill>
    );
}
