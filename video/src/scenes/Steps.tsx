import type { ReactNode } from 'react';
import {
    ArrowUpRight,
    CalendarDays,
    Check,
    CheckCheck,
    Globe2,
    Search,
    ShoppingBag,
    Sparkles,
} from 'lucide-react';
import { interpolate, useCurrentFrame } from 'remotion';
import { Caret, Line, Pop, progress, Rise, typed, useSpring } from '../anim';
import { RoomArt, SproutArt } from '../art';
import { C, SANS, SERIF } from '../theme';
import T from '../timeline.json';

/** The left-hand (or right-hand) copy block every step shares. */
function StepCopy({
    at,
    number,
    label,
    accent,
    plain,
    italic,
    body,
    x,
    width = 700,
    size = 80,
}: {
    at: number;
    number: string;
    label: string;
    accent: string;
    plain: ReactNode;
    italic: ReactNode;
    body: string;
    x: number;
    width?: number;
    size?: number;
}) {
    return (
        <div style={{ position: 'absolute', left: x, top: 0, bottom: 0, width, display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
            <Rise at={at} distance={20}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 18, fontSize: 24, fontWeight: 600, letterSpacing: '0.15em', textTransform: 'uppercase', color: accent }}>
                    <span style={{ display: 'grid', placeItems: 'center', width: 58, height: 58, borderRadius: 999, border: `2px solid ${accent}`, letterSpacing: 0 }}>{number}</span>
                    {label}
                </div>
            </Rise>
            <div style={{ marginTop: 34, fontSize: size, lineHeight: 1.04, fontWeight: 600, letterSpacing: '-0.05em', color: C.ink }}>
                <Line at={at + 4}>{plain}</Line>
                <Line at={at + 10}>
                    <span style={{ fontFamily: SERIF, fontWeight: 400, fontStyle: 'italic', letterSpacing: '-0.03em' }}>{italic}</span>
                </Line>
            </div>
            {body && (
                <Rise at={at + 18} distance={20}>
                    <p style={{ marginTop: 34, fontSize: 31, lineHeight: 1.5, color: C.muted, maxWidth: width - 60 }}>{body}</p>
                </Rise>
            )}
        </div>
    );
}

const card = {
    background: C.paper,
    border: `2px solid rgba(23,53,47,.13)`,
    borderRadius: 44,
    boxShadow: '0 50px 110px -50px rgba(23,53,47,.6)',
    overflow: 'hidden',
} as const;

function CardEnter({ at, children, x, y, width, rotate = 0 }: { at: number; children: ReactNode; x: number; y: number; width: number; rotate?: number }) {
    const s = useSpring(at, 18);
    return (
        <div
            style={{
                position: 'absolute',
                left: x,
                top: y,
                width,
                opacity: Math.min(1, s * 2),
                transform: `translateY(${(1 - s) * 140}px) rotate(${rotate + (1 - s) * 3}deg)`,
            }}
        >
            {children}
        </div>
    );
}

// --------------------------------------------------------------- 01 Research

const questions = ['Is a deep clean worth it?', 'What does a deep clean include?', 'How do I prepare for a cleaner?'];
const calendar = [
    { day: 'MON', date: '14', title: 'A calmer home starts here', bg: '#f2d9d0' },
    { day: 'WED', date: '16', title: 'What to expect from a deep clean', bg: '#e3e9da' },
    { day: 'FRI', date: '18', title: 'Getting ready for your first visit', bg: '#e1e7f4' },
];

export function Research() {
    const frame = useCurrentFrame();
    const R = T.research;
    const [start] = T.scenes.research;

    return (
        <div style={{ position: 'absolute', inset: 0, fontFamily: SANS, color: C.ink }}>
            <StepCopy
                at={start + 2}
                number="01"
                label="Research"
                accent={C.terracotta}
                plain="Avyo finds the questions"
                italic="your customers ask."
                body="Real searches and AI questions about your services, turned into a steady content calendar."
                x={150}
                width={680}
            />
            <CardEnter at={start - 4} x={900} y={120} width={880}>
                <div style={card}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '24px 36px', borderBottom: `2px solid ${C.lineSoft}` }}>
                        <span style={{ display: 'flex', alignItems: 'center', gap: 12, fontSize: 24, fontWeight: 600 }}>
                            <span style={{ width: 13, height: 13, borderRadius: 99, background: '#bb5137' }} /> Avyo
                        </span>
                        <span style={{ fontSize: 19, color: '#726f64' }}>Example business · Home &amp; Co.</span>
                    </div>
                    <div style={{ padding: '30px 36px 36px' }}>
                        <div style={{ fontSize: 15, fontWeight: 600, letterSpacing: '0.16em', color: '#726f64', textTransform: 'uppercase' }}>Questions your customers ask</div>
                        <div style={{ marginTop: 16, display: 'flex', flexDirection: 'column', gap: 12 }}>
                            {questions.map((q, i) => {
                                const tick = progress(frame, R.ticks[i], 10);
                                return (
                                    <Pop key={q} at={R.questions[i]} from={0.85} style={{ transformOrigin: 'left center' }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 16, padding: '15px 20px', borderRadius: 18, background: tick > 0.5 ? '#f4f6ee' : '#faf7f1', border: `2px solid ${tick > 0.5 ? '#cfdcc5' : C.lineSoft}`, fontSize: 25, fontWeight: 500 }}>
                                            <Search size={24} color={C.muted2} />
                                            <span style={{ flex: 1 }}>{q}</span>
                                            <span style={{ display: 'grid', placeItems: 'center', width: 34, height: 34, borderRadius: 99, background: C.sageSoft, transform: `scale(${tick})` }}>
                                                <Check size={21} color="#fff" strokeWidth={3} />
                                            </span>
                                        </div>
                                    </Pop>
                                );
                            })}
                        </div>
                        <div style={{ marginTop: 32, display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' }}>
                            <div>
                                <div style={{ fontSize: 15, fontWeight: 600, letterSpacing: '0.16em', color: '#726f64', textTransform: 'uppercase' }}>Your content calendar</div>
                                <div style={{ marginTop: 8, fontSize: 36, fontWeight: 600, letterSpacing: '-0.03em' }}>A good week ahead.</div>
                            </div>
                            <CalendarDays size={38} color={C.sageSoft} />
                        </div>
                        <div style={{ marginTop: 22, display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
                            {calendar.map((a, i) => (
                                <Pop key={a.day} at={R.cards[i]} from={0.4}>
                                    <div style={{ borderRadius: 20, border: `2px solid ${i === 1 ? C.sageSoft : C.lineSoft}`, background: i === 1 ? '#f4f6ee' : '#fff', padding: 18, height: 262 }}>
                                        <div style={{ fontSize: 16, fontWeight: 500, color: '#726f64' }}>{a.day}</div>
                                        <div style={{ fontSize: 32, fontWeight: 600 }}>{a.date}</div>
                                        <div style={{ marginTop: 12, height: 72, borderRadius: 12, background: a.bg, display: 'grid', placeItems: 'center' }}>
                                            <SproutArt variant={i} width={110} />
                                        </div>
                                        <div style={{ marginTop: 12, fontSize: 19, lineHeight: 1.3, fontWeight: 500 }}>{a.title}</div>
                                        <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 7, fontSize: 16, color: '#586b54', opacity: progress(frame, R.scheduled + i * 3, 10) }}>
                                            <span style={{ width: 8, height: 8, borderRadius: 9, background: C.sageSoft }} /> Scheduled
                                        </div>
                                    </div>
                                </Pop>
                            ))}
                        </div>
                    </div>
                </div>
            </CardEnter>
        </div>
    );
}

// ------------------------------------------------------------------ 02 Write

const bodyWords = 'A practical guide to preparing your home, choosing the right service, and making the most of your first visit.'.split(' ');

export function Write() {
    const frame = useCurrentFrame();
    const W = T.write;
    const [start] = T.scenes.write;
    const title = typed(W.title, frame, W.titleStart, W.titleFramesPerChar);
    const titleDone = title.length === W.title.length;
    const shown = Math.floor(interpolate(frame, [W.body, W.body + 36], [0, bodyWords.length], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }));
    const float = Math.sin(frame / 40) * 6;

    return (
        <div style={{ position: 'absolute', inset: 0, fontFamily: SANS, color: C.ink }}>
            <div style={{ position: 'absolute', left: 110, top: 70, width: 860, height: 940, borderRadius: 64, background: '#e9e5d9' }} />
            <CardEnter at={start - 4} x={200} y={120 + float} width={680} rotate={-2}>
                <div style={{ ...card, borderRadius: 28, border: '2px solid #d9d3c6' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '18px 30px', borderBottom: `2px solid ${C.lineSoft}`, fontSize: 16, color: '#746d60', letterSpacing: '0.04em' }}>
                        <span>HOME &amp; CO. / JOURNAL</span>
                        <span>Article preview</span>
                    </div>
                    <div style={{ height: 330, background: '#e2e7d9', overflow: 'hidden' }}>
                        <RoomArt frame={frame} at={W.art} />
                    </div>
                    <div style={{ padding: '34px 40px 40px', minHeight: 390 }}>
                        <Rise at={W.kicker} distance={12}>
                            <div style={{ fontSize: 16, fontWeight: 600, letterSpacing: '0.12em', color: '#a14f39', textTransform: 'uppercase' }}>A little know-how goes a long way</div>
                        </Rise>
                        <div style={{ marginTop: 16, minHeight: 124, fontFamily: SERIF, fontSize: 52, lineHeight: 1.15, letterSpacing: '-0.02em' }}>
                            {title}
                            {frame >= W.titleStart - 6 && <Caret height={46} visible={!titleDone || frame < W.body + 10} />}
                        </div>
                        <p style={{ marginTop: 18, fontSize: 23, lineHeight: 1.6, color: '#6c6f63', minHeight: 110 }}>
                            {bodyWords.map((w, i) => (
                                <span key={i} style={{ opacity: i < shown ? 1 : 0 }}>
                                    {w}{' '}
                                </span>
                            ))}
                        </p>
                        <Pop at={W.badge} from={0.7} style={{ transformOrigin: 'left center', marginTop: 16 }}>
                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 10, padding: '10px 18px', borderRadius: 999, background: '#eef2e7', fontSize: 20, fontWeight: 500, color: '#4f6b49' }}>
                                <Check size={20} strokeWidth={3} /> Based on your business brief
                            </span>
                        </Pop>
                    </div>
                </div>
            </CardEnter>
            <StepCopy
                at={start + 2}
                number="02"
                label="Write"
                accent={C.sage}
                plain="Helpful answers,"
                italic="in your voice."
                body="Articles that explain what you offer and guide visitors to the right service on your website."
                x={1070}
                width={720}
            />
        </div>
    );
}

// ---------------------------------------------------------------- 03 Publish

const older = [
    { title: 'Spring cleaning, room by room', date: 'Sep 9', bg: '#f2d9d0', art: 0 },
    { title: 'How often should carpets be cleaned?', date: 'Sep 5', bg: '#e1e7f4', art: 2 },
];

export function Publish() {
    const frame = useCurrentFrame();
    const P = T.publish;
    const [start] = T.scenes.publish;
    const review = frame >= P.toggle;
    const knob = progress(frame, P.toggle, 10);
    const pressed = frame >= P.approve && frame < P.approve + 5;
    const approved = frame >= P.approve;
    const fly = progress(frame, P.fly, 22);
    const slot = progress(frame, P.fly, 14);
    const toast = useSpring(P.toast, 14);

    // The thumbnail's trip from the review row into the journal's top slot.
    const from = { x: 46, y: 126 };
    const to = { x: 40, y: 528 };
    const arc = Math.sin(fly * Math.PI) * -60;

    return (
        <div style={{ position: 'absolute', inset: 0, fontFamily: SANS, color: C.ink }}>
            <StepCopy
                at={start + 2}
                number="03"
                label="Publish"
                accent={C.terracotta}
                plain="Published on your site."
                italic="Automatically, or after your review."
                body=""
                x={150}
                width={660}
                size={70}
            />
            <Rise at={start + 24} distance={16} style={{ position: 'absolute', left: 150, top: 760, display: 'flex', gap: 14 }}>
                {['WordPress', 'Custom websites'].map((t) => (
                    <span key={t} style={{ display: 'inline-flex', alignItems: 'center', gap: 10, padding: '12px 22px', borderRadius: 999, border: `2px solid ${C.line}`, fontSize: 23, fontWeight: 500 }}>
                        <Check size={20} color={C.sage} strokeWidth={3} /> {t}
                    </span>
                ))}
            </Rise>

            <div style={{ position: 'absolute', left: 860, top: 70, width: 920 }}>
                {/* The Avyo side: publishing mode and the article waiting on it. */}
                <CardEnter at={start - 4} x={0} y={0} width={920}>
                    <div style={{ ...card, borderRadius: 32, padding: '26px 30px' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <span style={{ display: 'flex', alignItems: 'center', gap: 12, fontSize: 22, fontWeight: 600 }}>
                                <span style={{ width: 12, height: 12, borderRadius: 99, background: '#bb5137' }} /> Publishing mode
                            </span>
                            <div style={{ position: 'relative', display: 'flex', padding: 6, borderRadius: 16, background: '#f0eee5', fontSize: 20, fontWeight: 500 }}>
                                <div style={{ position: 'absolute', top: 6, bottom: 6, left: 6 + knob * 150, width: 150 + knob * 18, borderRadius: 11, background: '#fff', boxShadow: '0 2px 6px rgba(23,53,47,.15)' }} />
                                <span style={{ position: 'relative', width: 150, textAlign: 'center', padding: '11px 0', color: review ? '#696b60' : C.ink }}>Automatic</span>
                                <span style={{ position: 'relative', width: 168, textAlign: 'center', padding: '11px 0', color: review ? C.ink : '#696b60' }}>Review first</span>
                            </div>
                        </div>
                        <div style={{ marginTop: 22, display: 'flex', alignItems: 'center', gap: 20, padding: 16, borderRadius: 20, background: C.ink, color: '#fffaf0' }}>
                            <div style={{ width: 96, height: 64, borderRadius: 12, background: '#e3e9da', display: 'grid', placeItems: 'center', opacity: fly > 0 ? 0.35 : 1 }}>
                                <SproutArt variant={1} width={84} />
                            </div>
                            <div style={{ flex: 1 }}>
                                <div style={{ fontSize: 23, fontWeight: 500 }}>What to expect from a deep clean</div>
                                <div style={{ marginTop: 4, fontSize: 18, color: C.mist, display: 'flex', alignItems: 'center', gap: 8 }}>
                                    {approved ? <CheckCheck size={18} color="#edcc75" /> : <Sparkles size={18} color="#edcc75" />}
                                    {approved ? 'Approved · publishing at 09:00' : review ? 'Ready for your review' : 'Ready to publish at 09:00'}
                                </div>
                            </div>
                            <Pop at={P.toggle + 4} from={0.5}>
                                <div style={{ padding: '14px 26px', borderRadius: 999, background: approved ? C.sageSoft : C.gold, color: C.ink, fontSize: 20, fontWeight: 600, transform: `scale(${pressed ? 0.9 : 1})`, opacity: review ? 1 : 0 }}>
                                    {approved ? '✓ Approved' : 'Approve'}
                                </div>
                            </Pop>
                        </div>
                    </div>
                </CardEnter>

                {/* The customer's website. */}
                <CardEnter at={start + 4} x={0} y={300} width={920}>
                    <div style={{ ...card, borderRadius: 26, background: '#fff' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 18, padding: '16px 22px', background: '#f1ece2', borderBottom: `2px solid ${C.lineSoft}` }}>
                            <div style={{ display: 'flex', gap: 8 }}>
                                {['#e0a79a', '#e9cf8f', '#b5c7a8'].map((c) => (
                                    <span key={c} style={{ width: 14, height: 14, borderRadius: 9, background: c }} />
                                ))}
                            </div>
                            <div style={{ flex: 1, display: 'flex', alignItems: 'center', gap: 10, padding: '8px 18px', borderRadius: 999, background: '#fff', fontSize: 18, color: C.muted }}>
                                <Globe2 size={17} /> homeandco.com/journal
                            </div>
                        </div>
                        <div style={{ padding: '26px 40px 30px' }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 18, color: C.muted }}>
                                <span style={{ fontWeight: 600, letterSpacing: '0.16em', color: C.ink }}>HOME &amp; CO.</span>
                                <span style={{ display: 'flex', gap: 26 }}>
                                    <span>Services</span>
                                    <span style={{ color: C.ink, fontWeight: 600 }}>Journal</span>
                                    <span>Book a visit</span>
                                </span>
                            </div>
                            <div style={{ marginTop: 22, fontFamily: SERIF, fontSize: 44 }}>Journal</div>
                            <div style={{ marginTop: 14, display: 'flex', flexDirection: 'column' }}>
                                <div style={{ height: slot * 104, overflow: 'hidden' }}>
                                    <Post title="What to expect from a deep clean" date="Today · 09:00" bg="#e3e9da" art={1} fresh thumbHidden={fly < 1} />
                                </div>
                                {older.map((o) => (
                                    <Post key={o.title} {...o} />
                                ))}
                            </div>
                        </div>
                    </div>
                </CardEnter>

                {fly > 0 && fly < 1 && (
                    <div
                        style={{
                            position: 'absolute',
                            left: from.x + (to.x - from.x) * fly,
                            top: from.y + (to.y - from.y) * fly + arc,
                            width: 96,
                            height: 64,
                            borderRadius: 12,
                            background: '#e3e9da',
                            display: 'grid',
                            placeItems: 'center',
                            boxShadow: '0 20px 40px -12px rgba(23,53,47,.5)',
                            transformOrigin: 'top left',
                            transform: `scale(${1 + Math.sin(fly * Math.PI) * 0.3 + fly * 0.15})`,
                        }}
                    >
                        <SproutArt variant={1} width={84} />
                    </div>
                )}

                <div
                    style={{
                        position: 'absolute',
                        right: 36,
                        top: 800,
                        opacity: Math.min(1, toast * 2),
                        transform: `translateY(${(1 - toast) * 40}px)`,
                        display: 'flex',
                        alignItems: 'center',
                        gap: 14,
                        padding: '18px 28px',
                        borderRadius: 18,
                        background: C.ink,
                        color: C.cream,
                        fontSize: 23,
                        fontWeight: 500,
                        boxShadow: '0 30px 60px -20px rgba(23,53,47,.6)',
                    }}
                >
                    <span style={{ display: 'grid', placeItems: 'center', width: 32, height: 32, borderRadius: 99, background: C.sageSoft }}>
                        <Check size={19} color="#fff" strokeWidth={3} />
                    </span>
                    Published on homeandco.com · 09:00
                </div>
            </div>
        </div>
    );
}

function Post({ title, date, bg, art, fresh, thumbHidden }: { title: string; date: string; bg: string; art: number; fresh?: boolean; thumbHidden?: boolean }) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 22, padding: '14px 0', borderTop: `2px solid ${C.lineSoft}`, height: 104 }}>
            <div style={{ width: 110, height: 72, borderRadius: 12, background: bg, display: 'grid', placeItems: 'center', opacity: thumbHidden ? 0 : 1 }}>
                <SproutArt variant={art} width={90} />
            </div>
            <div style={{ flex: 1 }}>
                <div style={{ fontSize: 24, fontWeight: 500, color: fresh ? C.ink : '#8b877f' }}>{title}</div>
                <div style={{ marginTop: 4, fontSize: 17, color: '#8b877f' }}>{date}</div>
            </div>
            {fresh && (
                <span style={{ padding: '6px 14px', borderRadius: 999, background: '#fbe3d9', color: C.terracotta, fontSize: 16, fontWeight: 600, letterSpacing: '0.08em' }}>NEW</span>
            )}
        </div>
    );
}

// ---------------------------------------------------------------- 04 Measure

// Illustrative only: a gently rising, noisy weekly series.
const series = Array.from({ length: 26 }, (_, i) => {
    const trend = 18 + i * 2.9 + Math.pow(i / 25, 2) * 22;
    const wobble = Math.sin(i * 1.7) * 5 + Math.cos(i * 0.9) * 3;
    return trend + wobble;
});

export function Measure() {
    const frame = useCurrentFrame();
    const M = T.measure;
    const [start] = T.scenes.measure;
    const draw = progress(frame, M.chart, 80);
    const clicks = Math.round(1284 * draw);

    const W = 760;
    const H = 250;
    const max = Math.max(...series) * 1.1;
    const pts = series.map((v, i) => [(i / (series.length - 1)) * W, H - (v / max) * H] as const);
    const line = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ');
    const area = `${line} L${W} ${H} L0 ${H} Z`;
    const head = pts[Math.min(pts.length - 1, Math.floor(draw * (pts.length - 1)))];

    const rows = [
        { icon: <ArrowUpRight size={24} />, label: 'Search clicks', value: '1,284' },
        { icon: <Sparkles size={24} />, label: 'AI mentions & citations', value: '18' },
        { icon: <ShoppingBag size={24} />, label: 'Purchases, when connected', value: '23' },
    ];

    return (
        <div style={{ position: 'absolute', inset: 0, fontFamily: SANS, color: C.ink }}>
            <StepCopy
                at={start + 2}
                number="04"
                label="Measure"
                accent={C.blue}
                plain="See who finds you."
                italic={
                    <>
                        Learn what leads
                        <br />
                        to sales.
                    </>
                }
                body="Follow visits from search, mentions in AI answers and purchases once tracking is connected."
                x={150}
                width={760}
                size={74}
            />
            <CardEnter at={start - 4} x={930} y={110} width={860}>
                <div style={{ ...card, background: C.ink, color: '#fffaf0', border: 'none', padding: '34px 40px' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <span style={{ display: 'flex', alignItems: 'center', gap: 12, fontSize: 25, fontWeight: 500 }}>
                            <Sparkles size={26} color="#edcc75" /> Your growth, in view
                        </span>
                        <span style={{ padding: '8px 16px', borderRadius: 999, background: 'rgba(255,255,255,.08)', fontSize: 17, color: C.mist }}>Last 6 months</span>
                    </div>
                    <div style={{ marginTop: 26, fontSize: 18, color: C.mist }}>Search clicks</div>
                    <div style={{ fontSize: 78, fontWeight: 600, letterSpacing: '-0.04em', fontVariantNumeric: 'tabular-nums' }}>{clicks.toLocaleString('en-US')}</div>
                    <svg width={W} height={H + 10} style={{ marginTop: 10, overflow: 'visible' }}>
                        <defs>
                            <linearGradient id="fill" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0" stopColor="#f3cf6a" stopOpacity={0.35} />
                                <stop offset="1" stopColor="#f3cf6a" stopOpacity={0} />
                            </linearGradient>
                            <clipPath id="reveal">
                                <rect x={0} y={-20} width={W * draw} height={H + 40} />
                            </clipPath>
                        </defs>
                        {[0.25, 0.5, 0.75, 1].map((g) => (
                            <line key={g} x1={0} x2={W} y1={H * g} y2={H * g} stroke="rgba(255,255,255,.08)" strokeWidth={2} />
                        ))}
                        <g clipPath="url(#reveal)">
                            <path d={area} fill="url(#fill)" />
                            <path d={line} stroke="#f3cf6a" strokeWidth={5} fill="none" strokeLinejoin="round" strokeLinecap="round" />
                        </g>
                        {draw > 0 && <circle cx={head[0]} cy={head[1]} r={10} fill="#f3cf6a" stroke={C.ink} strokeWidth={4} />}
                    </svg>
                    <div style={{ marginTop: 22, display: 'flex', flexDirection: 'column', gap: 4 }}>
                        {rows.map((r, i) => (
                            <Rise key={r.label} at={M.rows[i]} distance={18}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 16, padding: '14px 0', borderTop: '2px solid rgba(255,255,255,.1)', fontSize: 24, color: C.mist }}>
                                    <span style={{ color: '#edcc75', display: 'flex' }}>{r.icon}</span>
                                    <span style={{ flex: 1 }}>{r.label}</span>
                                    <span style={{ fontSize: 28, fontWeight: 600, color: '#fffaf0', fontVariantNumeric: 'tabular-nums' }}>{r.value}</span>
                                </div>
                            </Rise>
                        ))}
                    </div>
                    <div style={{ marginTop: 10, fontSize: 16, color: 'rgba(211,221,206,.7)', opacity: progress(frame, M.note, 12) }}>Illustrative data · your dashboard shows real observations</div>
                </div>
            </CardEnter>
        </div>
    );
}
