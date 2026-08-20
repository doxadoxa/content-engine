import { useId } from 'react';
import type { SVGAttributes } from 'react';

import { cn } from '@/lib/utils';

/**
 * Aperture — the Avyo mark.
 *
 * A container-shaped tile opening at one corner: forest ink, a terracotta
 * quarter turning out of the bottom-right, and a circular bite taken out of the
 * corner itself. It is built from the interface's own geometry rather than
 * drawn beside it, which is why it sits naturally next to a card or an avatar
 * without a plate behind it.
 *
 * **Its own tile, not a glyph.** The mark this replaces was a stroked outline
 * that inherited `currentColor`, so every call site had to supply a coloured
 * box for it to sit in and each one picked its own. This one carries its two
 * colours, and the call sites carry none.
 *
 * **Drawn at the small-size proportions**, not the poster ones. The identity
 * steps the corner radius down as the mark shrinks — 12px at 88, 4px at 16 —
 * because a radius that stays proportional closes the corner opening at the
 * size a browser tab renders it. Since this component is never displayed above
 * about 36px, the whole geometry uses the tab-legible ratios and stays
 * identical to the favicon at every size.
 */
export default function AppLogoIcon({
    tone = 'auto',
    className,
    ...props
}: SVGAttributes<SVGSVGElement> & {
    /**
     * Which surface the mark is sitting on.
     *
     * `auto` follows the colour scheme. The other two are for the places that
     * are dark (or light) regardless of it — the signed-out panel is forest in
     * both schemes, so a `dark:` variant would get it wrong exactly half the
     * time.
     */
    tone?: 'auto' | 'ink' | 'cream';
}) {
    // The split sign-in layout renders the mark twice — once for the panel and
    // once for the narrow header — and two `<mask id="…">` with the same name
    // is invalid markup whose second reference silently resolves to the first.
    const mask = useId();

    const tile =
        tone === 'ink'
            ? 'fill-[#17352F]'
            : tone === 'cream'
              ? 'fill-[#F3ECDD]'
              : 'fill-[#17352F] dark:fill-[#F3ECDD]';

    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 32 32"
            className={cn(className)}
            {...props}
        >
            {/*
                The corner opening. A mask rather than a background-coloured
                circle painted on top: the mark sits on cards, on the forest
                panel and on a sidebar, and a fake notch would be a different
                wrong colour on each of them.
            */}
            <mask id={mask}>
                <rect width="32" height="32" rx="8" fill="#fff" />
                <circle cx="32" cy="32" r="10" fill="#000" />
            </mask>
            <g mask={`url(#${mask})`}>
                <rect width="32" height="32" rx="8" className={tile} />
                <path
                    d="M11 32A21 21 0 0 1 32 11v13a8 8 0 0 1-8 8Z"
                    fill="#D6533C"
                />
            </g>
        </svg>
    );
}
