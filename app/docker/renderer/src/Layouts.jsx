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
const DEFAULT_TYPEFACE = 'instrument-sans';
const DEFAULT_FAMILY = 'Instrument Sans';

/**
 * The brand's own face, declared from files this image carries.
 *
 * `App\Support\Brand\VisualStyle::TYPEFACES` is the other half of this: it
 * maps the slug to the family name and is the only list of what may be asked
 * for, because a family the image has no files for renders as whatever Chromium
 * falls back to — silently, and it would look right to whoever reviewed it on a
 * machine that had the font installed.
 *
 * Both weights, matching the two the layouts use: 400 for body and 600 for every
 * heading. The `latin-ext` file is not declared — the panels are latin, and the
 * subset that is loaded is the one whose absence would show.
 */
const FontFace = ({ typeface = DEFAULT_TYPEFACE, family = DEFAULT_FAMILY }) => (
    <style>{`
        @font-face { font-family:'${family}'; font-weight:400; font-style:normal;
            src:url('${staticFile(`fonts/${typeface}/${typeface}-latin-400-normal.woff2`)}') format('woff2'); }
        @font-face { font-family:'${family}'; font-weight:600; font-style:normal;
            src:url('${staticFile(`fonts/${typeface}/${typeface}-latin-600-normal.woff2`)}') format('woff2'); }
    `}</style>
);

const stack = (family = DEFAULT_FAMILY) =>
    `${family}, ui-sans-serif, system-ui, sans-serif`;

const Frame = ({ colour, ink, children, pad = 96, justify = 'flex-end', typeface, typefaceFamily }) => (
    <div style={{
        width: '100%', height: '100%', display: 'flex', flexDirection: 'column',
        justifyContent: justify, gap: 28, padding: pad, backgroundColor: colour,
        color: ink, fontFamily: stack(typefaceFamily), boxSizing: 'border-box', position: 'relative',
    }}>
        <FontFace typeface={typeface} family={typefaceFamily} />
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

/**
 * The marks a slide can carry, as paths rather than characters.
 *
 * **Not a font, and not because a font would be hard.** A "✓" in a string is a
 * glyph, and the brand's WOFF2 is a latin subset that has none — so it fell
 * through to whatever Chromium had, drew a tofu box, and reported success. That
 * is a fact about *characters*; it says nothing about icons, and the tick below
 * has always been drawn this way precisely because it does not depend on the
 * subset anybody trims next.
 *
 * **Three, and structural.** Each belongs to a layout rather than being chosen
 * per slide: a tick means "take this away", a cross means "this is the thing you
 * are doing now", an arrow means "here is the ask". A mark the model picked
 * would be decoration, and decoration competing with 66px type loses.
 *
 * Stroked in `currentColor`'s stead by an explicit prop, because these sit on
 * three different grounds — the fill, the accent, and the ink-coloured pill on a
 * `cta` — and each needs the colour that reads on the one it landed on.
 *
 * 24×24 viewBox, so the paths are the ones any icon set draws and a fourth can
 * be pasted in without rescaling.
 */
const MARKS = {
    tick: 'M4.5 12.5l5 5 10-11',
    cross: 'M6.5 6.5l11 11M17.5 6.5l-11 11',
    arrow: 'M4 12h14m0 0l-5.5-5.5M18 12l-5.5 5.5',
};

const Mark = ({ name, colour, size = 28, weight = 3.2 }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none">
        <path d={MARKS[name]} stroke={colour} strokeWidth={weight}
            strokeLinecap="round" strokeLinejoin="round" />
    </svg>
);

/**
 * A mark in a rounded square, which is how a tick sits in a checklist.
 *
 * Its own component because the `contrast` slide wants the same object at the
 * same size on a different ground, and two copies of a 52px rounded square are
 * two chances for one of them to drift.
 */
const Badge = ({ name, on, ink, size = 52 }) => (
    <div style={{ width:size, height:size, borderRadius:16, backgroundColor:on,
        display:'flex', alignItems:'center', justifyContent:'center', flexShrink:0 }}>
        <Mark name={name} colour={ink} />
    </div>
);

/**
 * A heading with one run of it in the brand's second colour.
 *
 * The accent used to reach the words on exactly one slide — the figure on a
 * `stat` — and everywhere else it was a rule, a tick or a fill. So a cover, the
 * slide most people see and most stop at, set its hook in one flat colour and
 * put the brand's colour in a 200px bar above it.
 *
 * A run of the heading rather than a second string: the words have to stay one
 * sentence that wraps as one sentence, or the highlight becomes a line of its
 * own and the hook is two fragments. Matched case-insensitively and drawn from
 * the heading's own characters, so the casing on the panel is whatever was
 * written rather than whatever was matched.
 *
 * Silent where the run is absent — the parser drops a highlight the heading
 * does not contain, and this is the second guard on the same fact, because a
 * template that threw here would lose a slide over a decoration.
 */
const Marked = ({ text, mark, ink }) => {
    const at = mark ? String(text).toLowerCase().indexOf(String(mark).toLowerCase()) : -1;

    if (at < 0) {
        return text;
    }

    return (
        <>
            {String(text).slice(0, at)}
            <span style={{ color: ink }}>{String(text).slice(at, at + String(mark).length)}</span>
            {String(text).slice(at + String(mark).length)}
        </>
    );
};

/**
 * 01 · Cover — the hook. Oversized, and it opens a gap rather than closing it.
 *
 * **The photograph is the ground, not a frame of its own.** It used to publish
 * ahead of the panels: `WebhookPayload` sorts by role and `hero` precedes
 * `inline`, so the first thing anybody saw was a wordless picture and the line
 * that earns the swipe was second — on the one frame that has to stop a scroll.
 * A carousel does not get two covers. It gets this one.
 *
 * **Legibility is bought, not assumed.** A generated photograph is whatever it
 * is, so the type is not simply laid over it: a scrim in the brand's own fill
 * runs from transparent at the top to nearly solid where the words are. Being
 * the fill rather than black keeps the cover the brand's colour — the picture
 * darkens into the same navy every other slide is, so the deck still reads as
 * one object — and the ink underneath is the ink that was already chosen to
 * read on it.
 *
 * Without a photograph it draws exactly as it did before, which is what a
 * deployment with no image provider gets, and what a carousel gets when the
 * hero has not been chosen yet.
 */
export const Cover = ({ typeface, typefaceFamily, heading, kicker, highlight, highlightInk, colour, ink, accent, photo, photoAnchor = 'bottom', index, total }) => (
    <Frame typeface={typeface} typefaceFamily={typefaceFamily} colour={colour} ink={ink} justify="flex-end" pad={0}>
        {photo && (
            <>
                <img
                    src={photo}
                    alt=""
                    style={{ position:'absolute', inset:0, width:'100%', height:'100%',
                        objectFit:'cover' }}
                />
                {/*
                    Weighted to the bottom because that is where the words are,
                    and stopped short of opaque at the very foot so the picture
                    is still a picture rather than a texture behind a panel.
                */}
                {/*
                    Run towards whichever end the words are on, which is the
                    quieter end of the picture — see App\Media\PhotoAnchor. The
                    scrim was fixed to the foot, and the first photograph it met
                    had its whole subject there: a gloved hand and a brush,
                    darkened to nearly solid so the hook could sit on them.
                */}
                <div style={{ position:'absolute', inset:0, background:
                    `linear-gradient(to ${photoAnchor === 'top' ? 'top' : 'bottom'}, ${colour}00 0%, ${colour}59 42%, ${colour}F2 78%, ${colour} 100%)` }} />
            </>
        )}

        <div style={{ position:'relative', display:'flex', flexDirection:'column',
            justifyContent: photoAnchor === 'top' ? 'flex-start' : 'flex-end',
            gap:28, height:'100%', padding:96, boxSizing:'border-box' }}>
            <Counter index={index} total={total} ink={ink} />
            {kicker && <div style={{ fontSize:38, fontWeight:600, letterSpacing:'0.14em',
                textTransform:'uppercase', opacity:.6 }}>{kicker}</div>}
            <Rule accent={accent} width={200} />
            <div style={{ fontSize:124, lineHeight:1, fontWeight:600, letterSpacing:'-0.035em',
                overflowWrap:'anywhere', hyphens:'auto' }}>
                <Marked text={heading} mark={highlight} ink={highlightInk} />
            </div>
            <div style={{ fontSize:36, opacity:.55, marginTop:16, display:'flex',
                alignItems:'center', gap:14 }}>
                Swipe
                <Mark name="arrow" colour={ink} size={34} weight={2.6} />
            </div>
        </div>
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
export const Stat = ({ typeface, typefaceFamily, figure, caption, colour, ink, accentType, index, total }) => (
    <Frame typeface={typeface} typefaceFamily={typefaceFamily} colour={colour} ink={ink} justify="center">
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
export const Contrast = ({ typeface, typefaceFamily, beforeLabel, before, afterLabel, after, colour, ink, accent, onAccent, index, total }) => (
    <Frame typeface={typeface} typefaceFamily={typefaceFamily} colour={colour} ink={ink} justify="center" pad={0}>
        <Counter index={index} total={total} ink={ink} />
        <div style={{ display:'flex', flexDirection:'column', height:'100%' }}>
            <div style={{ flex:1, padding:'120px 96px 60px', display:'flex',
                flexDirection:'column', justifyContent:'flex-end', gap:20 }}>
                <div style={{ display:'flex', alignItems:'center', gap:18 }}>
                    {/* Dimmed with the half it labels — the whole point of the
                        upper band is that it is the thing being left behind. */}
                    <div style={{ opacity:.45 }}>
                        <Badge name="cross" on="transparent" ink={ink} size={40} />
                    </div>
                    <div style={{ fontSize:34, fontWeight:600, letterSpacing:'0.14em',
                        textTransform:'uppercase', opacity:.5 }}>{beforeLabel}</div>
                </div>
                <div style={{ fontSize:66, lineHeight:1.1, fontWeight:600,
                    letterSpacing:'-0.02em', opacity:.45 }}>{before}</div>
            </div>
            <div style={{ flex:1, padding:'60px 96px 120px', display:'flex',
                flexDirection:'column', justifyContent:'flex-start', gap:20,
                backgroundColor:accent, color:onAccent }}>
                <div style={{ display:'flex', alignItems:'center', gap:18 }}>
                    <Badge name="tick" on="transparent" ink={onAccent} size={40} />
                    <div style={{ fontSize:34, fontWeight:600, letterSpacing:'0.14em',
                        textTransform:'uppercase', opacity:.65 }}>{afterLabel}</div>
                </div>
                <div style={{ fontSize:66, lineHeight:1.1, fontWeight:600,
                    letterSpacing:'-0.02em' }}>{after}</div>
            </div>
        </div>
    </Frame>
);

/** 04 · Step — today's panel, used where it actually belongs. */
export const Step = ({ typeface, typefaceFamily, step, heading, body, colour, ink, accent, index, total }) => (
    <Frame typeface={typeface} typefaceFamily={typefaceFamily} colour={colour} ink={ink} justify="flex-end">
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
export const Checklist = ({ typeface, typefaceFamily, heading, items = [], colour, ink, accent, index, total }) => (
    <Frame typeface={typeface} typefaceFamily={typefaceFamily} colour={colour} ink={ink} justify="center">
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
                    <div style={{ marginTop:4 }}>
                        <Badge name="tick" on={accent} ink={colour} />
                    </div>
                    <div style={{ fontSize:46, lineHeight:1.3 }}>{item}</div>
                </div>
            ))}
        </div>
    </Frame>
);

/** 06 · Statement — one sentence, nothing else in the frame. */
export const Statement = ({ typeface, typefaceFamily, heading, highlight, highlightInk, colour, ink, accent, index, total }) => (
    <Frame typeface={typeface} typefaceFamily={typefaceFamily} colour={colour} ink={ink} justify="center">
        <Counter index={index} total={total} ink={ink} />
        <Rule accent={accent} width={200} />
        <div style={{ fontSize:104, lineHeight:1.06, fontWeight:600, letterSpacing:'-0.03em',
            overflowWrap:'anywhere', hyphens:'auto' }}>
            <Marked text={heading} mark={highlight} ink={highlightInk} />
        </div>
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
export const Cta = ({ typeface, typefaceFamily, heading, body, action, accent, onAccent, index, total }) => (
    <Frame typeface={typeface} typefaceFamily={typefaceFamily} colour={accent} ink={onAccent} justify="center">
        <Counter index={index} total={total} ink={onAccent} />
        <div style={{ fontSize:92, lineHeight:1.06, fontWeight:600, letterSpacing:'-0.03em' }}>{heading}</div>
        {body && <div style={{ fontSize:46, lineHeight:1.35, opacity:.8, maxWidth:'88%' }}>{body}</div>}
        {action && <div style={{ marginTop:24, alignSelf:'flex-start', padding:'28px 56px',
            borderRadius:999, backgroundColor:onAccent, color:accent, fontSize:44,
            fontWeight:600, display:'flex', alignItems:'center', gap:20 }}>
            {action}
            {/* On the pill rather than beside it: the arrow is part of the ask,
                and a mark floating next to a button reads as a separate thing
                to look at. */}
            <Mark name="arrow" colour={accent} size={38} weight={3} />
        </div>}
    </Frame>
);
