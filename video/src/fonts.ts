import { continueRender, delayRender, staticFile } from 'remotion';

// The marketing page's own Instrument Sans, vendored from app/resources/fonts.
// Serif italics fall back to Georgia, exactly as Tailwind's `font-serif` does on
// the page itself.
const handle = delayRender('Loading Instrument Sans');

Promise.all(
    [400, 500, 600].map((weight) =>
        new FontFace(
            'Instrument Sans',
            `url(${staticFile(`instrument-sans-latin-${weight}-normal.woff2`)}) format('woff2')`,
            { weight: String(weight) },
        )
            .load()
            .then((face) => document.fonts.add(face)),
    ),
).then(() => continueRender(handle));
