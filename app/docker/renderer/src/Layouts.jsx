import { staticFile } from 'remotion';

/**
 * The shapes a carousel slide can take.
 *
 * **Seven, because one was the reason carousels read as boring.** `Panel` has a
 * heading and a body and nothing else, so every slide of every carousel came out
 * as the same rectangle — and no amount of better writing fixes a format with
 * one shape in it. A figure could not be shown as a figure, a comparison could
 * not be shown as a comparison, and the cover looked exactly like step four.
 *
 * The alternative considered was letting the model author raw HTML per slide,
 * the way the open-carrusel project does. Rejected: that is right for a design
 * app where a person previews every slide, and wrong for a pipeline that drafts
 * twenty posts a month unattended — it trades the Brand Brief's guarantees and
 * every regression test for variety we can get inside a typed set.
 *
 * Every colour is a prop from {@see VisualStyle}. A layout that baked one in
 * would be a second place the brand lives, and the two would disagree the first
 * time somebody changed one. `App\Enums\SlideLayout` is the PHP half of this
 * contract: the field names below are the ones it validates and sends.
 *
 * **Nothing here may depend on a glyph.** The typeface ships as a latin subset,
 * so a "✓" in a string renders as tofu — silently, and no test sees it. Marks
 * are drawn as SVG paths.
 */
const FontFace = () => (
    <style>{`
        @font-face { font-family:'Instrument Sans'; font-weight:400; font-style:normal;
            src:url('${staticFile('fonts/instrument-sans/instrument-sans-latin-400-normal.woff2')}') format('woff2'); }
        @font-face { font-family:'Instrument Sans'; font-weight:600; font-style:normal;
            src:url('${staticFile('fonts/instrument-sans/instrument-sans-latin-600-normal.woff2')}') format('woff2'); }
    `}</style>
);

const FAMILY = 'Instrument Sans, ui-sans-serif, system-ui, sans-serif';

const Frame = ({ colour, ink, children, pad = 96, justify = 'flex-end' }) => (
    <div style={{
        width: '100%', height: '100%', display: 'flex', flexDirection: 'column',
        justifyContent: justify, gap: 28, padding: pad, backgroundColor: colour,
        color: ink, fontFamily: FAMILY, boxSizing: 'border-box', position: 'relative',
    }}>
        <FontFace />
        {children}
    </div>
);

const Counter = ({ index, total, ink }) => total > 1 ? (
    <div style={{ position:'absolute', top:64, right:96, fontSize:34, fontWeight:500,
        opacity:.55, letterSpacing:'0.06em', color: ink }}>{index} / {total}</div>
) : null;

const Rule = ({ accent, width = 140 }) => (
    <div style={{ width, height:10, borderRadius:999, backgroundColor:accent, flexShrink:0 }} />
);

/** 01 · Cover — the hook. Oversized, and it opens a gap rather than closing it. */
export const Cover = ({ heading, kicker, colour, ink, accent, index, total }) => (
    <Frame colour={colour} ink={ink} justify="flex-end">
        <Counter index={index} total={total} ink={ink} />
        {kicker && <div style={{ fontSize:38, fontWeight:600, letterSpacing:'0.14em',
            textTransform:'uppercase', opacity:.6 }}>{kicker}</div>}
        <Rule accent={accent} width={200} />
        <div style={{ fontSize:124, lineHeight:1, fontWeight:600, letterSpacing:'-0.035em',
            overflowWrap:'anywhere', hyphens:'auto' }}>{heading}</div>
        <div style={{ fontSize:36, opacity:.55, marginTop:16 }}>Swipe →</div>
    </Frame>
);

/**
 * 02 · Stat — one figure at display size. The thing a flat panel cannot do.
 *
 * The figure is drawn in `accentType`, not `accent`: a brand whose accent is
 * too close to its fill keeps the accent everywhere it is a graphic and loses it
 * here, because a 300px number below 3:1 is the most washed-out thing on the
 * whole carousel.
 */
export const Stat = ({ figure, caption, colour, ink, accentType, index, total }) => (
    <Frame colour={colour} ink={ink} justify="center">
        <Counter index={index} total={total} ink={ink} />
        <div style={{ fontSize:300, lineHeight:.9, fontWeight:600, letterSpacing:'-0.05em',
            color:accentType }}>{figure}</div>
        <div style={{ fontSize:52, lineHeight:1.25, fontWeight:400, opacity:.9,
            maxWidth:'85%' }}>{caption}</div>
    </Frame>
);

/**
 * 03 · Contrast — this against that. Two halves, the second accented.
 *
 * The half that matters is the one that gets the colour, and it is the lower
 * one: a reader's eye lands last where the swipe leaves it.
 */
export const Contrast = ({ beforeLabel, before, afterLabel, after, colour, ink, accent, onAccent, index, total }) => (
    <Frame colour={colour} ink={ink} justify="center" pad={0}>
        <Counter index={index} total={total} ink={ink} />
        <div style={{ display:'flex', flexDirection:'column', height:'100%' }}>
            <div style={{ flex:1, padding:'120px 96px 60px', display:'flex',
                flexDirection:'column', justifyContent:'flex-end', gap:20 }}>
                <div style={{ fontSize:34, fontWeight:600, letterSpacing:'0.14em',
                    textTransform:'uppercase', opacity:.5 }}>{beforeLabel}</div>
                <div style={{ fontSize:66, lineHeight:1.1, fontWeight:600,
                    letterSpacing:'-0.02em', opacity:.45 }}>{before}</div>
            </div>
            <div style={{ flex:1, padding:'60px 96px 120px', display:'flex',
                flexDirection:'column', justifyContent:'flex-start', gap:20,
                backgroundColor:accent, color:onAccent }}>
                <div style={{ fontSize:34, fontWeight:600, letterSpacing:'0.14em',
                    textTransform:'uppercase', opacity:.65 }}>{afterLabel}</div>
                <div style={{ fontSize:66, lineHeight:1.1, fontWeight:600,
                    letterSpacing:'-0.02em' }}>{after}</div>
            </div>
        </div>
    </Frame>
);

/** 04 · Step — today's panel, used where it actually belongs. */
export const Step = ({ step, heading, body, colour, ink, accent, index, total }) => (
    <Frame colour={colour} ink={ink} justify="flex-end">
        <Counter index={index} total={total} ink={ink} />
        <div style={{ position:'absolute', top:200, left:96, fontSize:220, lineHeight:1,
            fontWeight:600, color:accent, opacity:.22, letterSpacing:'-0.05em' }}>{step}</div>
        <Rule accent={accent} />
        <div style={{ fontSize:88, lineHeight:1.05, fontWeight:600, letterSpacing:'-0.02em',
            overflowWrap:'anywhere', hyphens:'auto' }}>{heading}</div>
        {body && <div style={{ fontSize:44, lineHeight:1.35, opacity:.85 }}>{body}</div>}
    </Frame>
);

/** 05 · Checklist — a set, seen as a set. */
export const Checklist = ({ heading, items = [], colour, ink, accent, index, total }) => (
    <Frame colour={colour} ink={ink} justify="center">
        <Counter index={index} total={total} ink={ink} />
        <Rule accent={accent} />
        <div style={{ fontSize:76, lineHeight:1.08, fontWeight:600, letterSpacing:'-0.025em',
            marginBottom:24 }}>{heading}</div>
        <div style={{ display:'flex', flexDirection:'column', gap:30 }}>
            {items.map((item, i) => (
                <div key={i} style={{ display:'flex', gap:26, alignItems:'flex-start' }}>
                    {/*
                        An SVG path, not a "✓". The brand's WOFF2 is a latin
                        subset and has no U+2713, so the glyph fell through to
                        whatever Chromium had — which was nothing, and drew a
                        tofu box. A tick that depends on a font we subset is a
                        tick that breaks the first time somebody trims the
                        subset, and no test would see it.
                    */}
                    <div style={{ width:52, height:52, borderRadius:16, backgroundColor:accent,
                        display:'flex', alignItems:'center', justifyContent:'center',
                        flexShrink:0, marginTop:4 }}>
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                            <path d="M4.5 12.5l5 5 10-11" stroke={colour} strokeWidth="3.2"
                                strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                    </div>
                    <div style={{ fontSize:46, lineHeight:1.3 }}>{item}</div>
                </div>
            ))}
        </div>
    </Frame>
);

/** 06 · Statement — one sentence, nothing else in the frame. */
export const Statement = ({ heading, colour, ink, accent, index, total }) => (
    <Frame colour={colour} ink={ink} justify="center">
        <Counter index={index} total={total} ink={ink} />
        <Rule accent={accent} width={200} />
        <div style={{ fontSize:104, lineHeight:1.06, fontWeight:600, letterSpacing:'-0.03em',
            overflowWrap:'anywhere', hyphens:'auto' }}>{heading}</div>
    </Frame>
);

/**
 * 07 · CTA — the last slide asks for exactly one thing.
 *
 * Inverted onto the accent so the end of the carousel is visibly the end. The
 * type is `onAccent` rather than the brand colour: forest on terracotta is
 * 2.22:1, which made the one slide asking for the follow the least readable of
 * the seven.
 */
export const Cta = ({ heading, body, action, accent, onAccent, index, total }) => (
    <Frame colour={accent} ink={onAccent} justify="center">
        <Counter index={index} total={total} ink={onAccent} />
        <div style={{ fontSize:92, lineHeight:1.06, fontWeight:600, letterSpacing:'-0.03em' }}>{heading}</div>
        {body && <div style={{ fontSize:46, lineHeight:1.35, opacity:.8, maxWidth:'88%' }}>{body}</div>}
        {action && <div style={{ marginTop:24, alignSelf:'flex-start', padding:'28px 56px',
            borderRadius:999, backgroundColor:onAccent, color:accent, fontSize:44,
            fontWeight:600 }}>{action}</div>}
    </Frame>
);
