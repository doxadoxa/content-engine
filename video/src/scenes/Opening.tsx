import { Search, Sparkles } from 'lucide-react';
import { AbsoluteFill, interpolate, useCurrentFrame } from 'remotion';
import { Caret, Line, Orbs, Pop, progress, typed, useSpring } from '../anim';
import { C, SANS, SERIF } from '../theme';
import T from '../timeline.json';

const Q = T.question;

const chips: { text: string; kind: 'search' | 'ai'; x: number; y: number; drift: number }[] = [
    { text: 'emergency plumber open now', kind: 'search', x: 150, y: 150, drift: 0 },
    { text: 'Which dentist near me takes new patients?', kind: 'ai', x: 1010, y: 118, drift: 1 },
    { text: 'Recommend a reliable electrician nearby', kind: 'ai', x: 240, y: 300, drift: 2 },
    { text: 'best sourdough bakery nearby', kind: 'search', x: 1190, y: 282, drift: 3 },
    { text: 'How much does a family photo shoot cost?', kind: 'ai', x: 110, y: 640, drift: 4 },
    { text: 'is a deep clean worth it?', kind: 'search', x: 1210, y: 650, drift: 5 },
];

function Chip({ text, kind, x, y, drift, at, leave }: (typeof chips)[number] & { at: number; leave: number }) {
    const frame = useCurrentFrame();
    const float = Math.sin((frame + drift * 23) / 28) * 6;
    const away = leave * (drift % 2 ? 1 : -1);
    return (
        <div
            style={{
                position: 'absolute',
                left: x + away * 160,
                top: y + float - leave * 120,
                opacity: 1 - leave,
            }}
        >
            <Pop at={at}>
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 14,
                        padding: '18px 28px 18px 20px',
                        borderRadius: 999,
                        background: kind === 'ai' ? C.ink : C.paper,
                        color: kind === 'ai' ? C.cream : C.ink,
                        border: kind === 'ai' ? 'none' : `2px solid ${C.line}`,
                        boxShadow: '0 18px 40px -22px rgba(23,53,47,.45)',
                        fontSize: 29,
                        fontWeight: 500,
                        whiteSpace: 'nowrap',
                    }}
                >
                    <span
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 7,
                            padding: '5px 12px',
                            borderRadius: 999,
                            fontSize: 17,
                            fontWeight: 600,
                            letterSpacing: '0.06em',
                            textTransform: 'uppercase',
                            background: kind === 'ai' ? 'rgba(243,207,106,.18)' : '#efe8da',
                            color: kind === 'ai' ? C.gold : C.muted,
                        }}
                    >
                        {kind === 'ai' ? <Sparkles size={17} /> : <Search size={17} />}
                        {kind === 'ai' ? 'AI' : 'Search'}
                    </span>
                    {text}
                </div>
            </Pop>
        </div>
    );
}

export function Question() {
    const frame = useCurrentFrame();
    const [, end] = T.scenes.question;
    const leave = progress(frame, end - 22, 22);
    const enter = useSpring(0, 200, 20);
    const query = typed(Q.query, frame, Q.typeStart, Q.framesPerChar);
    const done = query.length === Q.query.length;

    return (
        <AbsoluteFill style={{ fontFamily: SANS, color: C.ink }}>
            <Orbs disc={[1600, -80, 360]} ring={[-120, 820, 380]} />
            {chips.map((chip, i) => (
                <Chip key={chip.text} {...chip} at={Q.chips[i]} leave={progress(frame, end - 24 + i * 2, 18)} />
            ))}
            <div
                style={{
                    position: 'absolute',
                    left: 960,
                    top: 470,
                    transform: `translate(-50%, -50%) scale(${(0.9 + 0.1 * enter) * (1 - leave * 0.1)})`,
                    opacity: enter * (1 - leave),
                }}
            >
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 26,
                        width: 1080,
                        padding: '34px 44px',
                        borderRadius: 999,
                        background: '#fff',
                        border: `2px solid ${C.line}`,
                        boxShadow: '0 40px 90px -40px rgba(23,53,47,.55)',
                        fontSize: 46,
                        fontWeight: 500,
                        letterSpacing: '-0.02em',
                    }}
                >
                    <Search size={46} color={C.muted2} strokeWidth={2.2} />
                    <span>
                        {query}
                        <Caret height={52} visible={!done || frame < Q.chips[0]} />
                    </span>
                </div>
            </div>
            <div
                style={{
                    position: 'absolute',
                    left: 0,
                    right: 0,
                    top: 830,
                    textAlign: 'center',
                    fontSize: 68,
                    fontWeight: 600,
                    letterSpacing: '-0.045em',
                    opacity: 1 - leave,
                }}
            >
                <Line at={Q.headline}>
                    Every day, people ask Google and AI{' '}
                    <span style={{ fontFamily: SERIF, fontWeight: 400, fontStyle: 'italic', color: C.terracotta }}>
                        about what you do.
                    </span>
                </Line>
            </div>
        </AbsoluteFill>
    );
}

export function Gap() {
    const frame = useCurrentFrame();
    const G = T.gap;
    const [start, end] = T.scenes.gap;
    const zoom = interpolate(frame, [start, end], [1, 1.05]);
    const words = ['will', 'they', 'find'];
    const underline = progress(frame, G.underline, 20);

    return (
        <AbsoluteFill style={{ fontFamily: SANS, color: C.ink, alignItems: 'center', justifyContent: 'center' }}>
            <Orbs disc={[1560, 720, 300]} ring={[140, -90, 320]} />
            <div
                style={{
                    transform: `scale(${zoom})`,
                    textAlign: 'center',
                    fontSize: 132,
                    lineHeight: 1.06,
                    fontWeight: 600,
                    letterSpacing: '-0.045em',
                }}
            >
                <Line at={G.line1}>When they ask,</Line>
                <div style={{ display: 'flex', justifyContent: 'center', gap: '0.26em', marginTop: 10 }}>
                    {words.map((word, i) => (
                        <Line key={word} at={G.line2 + i * 4}>
                            {word}
                        </Line>
                    ))}
                    <div style={{ position: 'relative' }}>
                        <Line at={G.line2 + 12}>
                            <span
                                style={{
                                    fontFamily: SERIF,
                                    fontWeight: 400,
                                    fontStyle: 'italic',
                                    letterSpacing: '-0.03em',
                                    color: C.terracotta,
                                    fontSize: 148,
                                }}
                            >
                                you?
                            </span>
                        </Line>
                        <svg
                            viewBox="0 0 300 40"
                            width={300}
                            height={40}
                            style={{ position: 'absolute', left: 0, bottom: -24, overflow: 'visible' }}
                        >
                            <path
                                d="M6 26C70 12 170 8 294 18"
                                stroke={C.terracotta}
                                strokeWidth={9}
                                strokeLinecap="round"
                                fill="none"
                                pathLength={1}
                                strokeDasharray={1}
                                strokeDashoffset={1 - underline}
                            />
                        </svg>
                    </div>
                </div>
            </div>
        </AbsoluteFill>
    );
}
