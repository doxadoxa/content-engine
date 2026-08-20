import { staticFile } from 'remotion';
/**
 * One carousel slide, as a component rather than as a prompt.
 *
 * This is the whole argument for standing up a renderer. A teaching post is a
 * sequence of steps with headings, and every way we had of putting one on a
 * screen was wrong: an image model garbles text reliably enough that the engine
 * forbids words in generated pictures outright, so a how-to shipped as slide
 * text in the caption with one unrelated photograph attached to the post.
 *
 * Here the words are drawn by a browser from a real typeface, which is the
 * thing browsers have always been good at. Nothing is generated, nothing is
 * paid for, and the text is exactly what was written.
 *
 * Everything visual comes in as props from the Brand Brief — see VisualStyle.
 * A template with a colour baked into it would be a second place the brand
 * lives, and the two would disagree the first time somebody changed one.
 *
 * The typeface is the app's own WOFF2, loaded straight from the files the site
 * already ships. A browser reads that format natively, which is the quiet
 * advantage of rendering in one: same face as the product, no conversion, no
 * second copy to keep in step.
 *
 * The URL comes from `staticFile` rather than being written by hand. A literal
 * `/fonts/...` looks right and is not: the bundler serves the public directory
 * under its own origin, the request 404s, and Chromium falls back — silently,
 * to whatever it has, which turned out to be a monospace face. Nothing fails,
 * the panel simply comes out in the wrong typeface, which no test would notice.
 */
const DEFAULT_TYPEFACE = 'instrument-sans';
const DEFAULT_FAMILY = 'Instrument Sans';

const FontFace = ({ typeface = DEFAULT_TYPEFACE, family = DEFAULT_FAMILY }) => (
    <style>{`
        @font-face {
            font-family: '${family}';
            font-style: normal;
            font-weight: 400;
            src: url('${staticFile(`fonts/${typeface}/${typeface}-latin-400-normal.woff2`)}') format('woff2');
        }
        @font-face {
            font-family: '${family}';
            font-style: normal;
            font-weight: 600;
            src: url('${staticFile(`fonts/${typeface}/${typeface}-latin-600-normal.woff2`)}') format('woff2');
        }
    `}</style>
);

export const Panel = ({
    typeface = DEFAULT_TYPEFACE,
    typefaceFamily = DEFAULT_FAMILY,
    heading,
    body,
    index,
    total,
    colour,
    ink,
    position,
    accent,
}) => {
    // The channel decides the frame, so the panel cannot assume its own shape.
    // `position` is where the brand puts its words; the slide's counter always
    // sits opposite them, so the two never collide whichever end is chosen.
    const justify =
        position === 'top'
            ? 'flex-start'
            : position === 'centre'
              ? 'center'
              : 'flex-end';

    return (
        <div
            style={{
                width: '100%',
                height: '100%',
                display: 'flex',
                flexDirection: 'column',
                justifyContent: justify,
                gap: 28,
                padding: 96,
                backgroundColor: colour,
                color: ink,
                fontFamily: `${typefaceFamily}, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif`,
                boxSizing: 'border-box',
            }}
        >
            <FontFace typeface={typeface} family={typefaceFamily} />
            {total > 1 && (
                <div
                    style={{
                        position: 'absolute',
                        top: 64,
                        right: 96,
                        fontSize: 34,
                        fontWeight: 500,
                        opacity: 0.55,
                        letterSpacing: '0.06em',
                    }}
                >
                    {index} / {total}
                </div>
            )}

            {/*
              A rule rather than a flourish. It gives the eye somewhere to start
              on a panel that is otherwise a rectangle of colour, and it is the
              one place the accent appears — a second accent would compete with
              the heading for the only job the panel has.
            */}
            <div
                style={{
                    width: 140,
                    height: 10,
                    borderRadius: 999,
                    backgroundColor: accent,
                    flexShrink: 0,
                }}
            />

            <div
                style={{
                    fontSize: 92,
                    lineHeight: 1.05,
                    fontWeight: 600,
                    letterSpacing: '-0.02em',
                    // Long words in Portuguese and Russian overflow a fixed
                    // frame far more often than in English, and a heading
                    // running off the edge is worse than one that hyphenates.
                    overflowWrap: 'anywhere',
                    hyphens: 'auto',
                }}
            >
                {heading}
            </div>

            {body !== '' && (
                <div
                    style={{
                        fontSize: 46,
                        lineHeight: 1.35,
                        fontWeight: 400,
                        opacity: 0.9,
                        overflowWrap: 'anywhere',
                    }}
                >
                    {body}
                </div>
            )}
        </div>
    );
};
