<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a machine made this picture or a person took it.
 *
 * The distinction is worth a column because it is the one thing about an image
 * that no later step can work out for itself, and three of them need it: a
 * composite has to name the real photograph as the anchor it may not alter, a
 * brand overlay only ever goes on one that was not generated, and an operator
 * choosing between candidates is entitled to see which of them somebody
 * actually went and photographed.
 *
 * It is also the honest label on the thing the whole media studio exists to
 * change. A generated picture of a generic kitchen is stock — minted rather
 * than licensed, and no more distinguishable for it. The real van, the real
 * crew and the real before-and-after are the only images a competitor cannot
 * also produce.
 */
enum AssetSource: string
{
    /** Drawn by an image provider from a prompt this engine wrote. */
    case Generated = 'generated';

    /** Uploaded by an operator. A photograph of something that happened. */
    case Uploaded = 'uploaded';

    /**
     * Laid out by this engine and drawn by a browser.
     *
     * Neither of the two above, and the difference is not bookkeeping. A
     * rendered panel is deterministic — the same slide and the same brand
     * produce the same picture — it costs nothing, and its text is exactly what
     * was written rather than what a model made of it. That last property is
     * the whole reason it exists: the engine forbids words inside generated
     * pictures because every image model garbles them, which left a teaching
     * carousel with nothing legible to show.
     */
    case Rendered = 'rendered';

    public function label(): string
    {
        return match ($this) {
            self::Generated => 'Generated',
            self::Uploaded => 'Photograph',
            self::Rendered => 'Panel',
        };
    }
}
