<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The shape one carousel slide takes.
 *
 * **The whole reason carousels stopped being boring.** There used to be one
 * template — a heading and a body on a rectangle of brand colour — so a six
 * slide carousel was six identical rectangles. That is not a writing problem and
 * better copy never fixed it: a figure could not be shown as a figure, a
 * comparison could not be shown as a comparison, and the cover was drawn exactly
 * like step four.
 *
 * **Typed rather than free-form.** The obvious alternative, and the one the
 * open-carrusel project takes, is to let the model author HTML per slide. That
 * is right for a design tool where a person previews every slide before it
 * exports, and wrong here: this engine drafts a month of posts unattended, and
 * arbitrary CSS in the render path would drift off-brand and break at
 * 1080×1350 with no test able to see it. A closed set buys the same variety and
 * keeps the Brand Brief authoritative.
 *
 * Each case owns three facts — what the model must supply, what the renderer
 * calls it, and how one degrades into another. Keeping them here rather than
 * spread across the prompt, the parser and the drawer is what stops the three
 * disagreeing; `docker/renderer/src/Layouts.jsx` is the other half of the
 * contract and its prop names are {@see fields()}.
 */
enum SlideLayout: string
{
    /** The hook. Its job is to open a gap the rest of the carousel closes. */
    case Cover = 'cover';

    /** One sentence, nothing else in the frame. */
    case Statement = 'statement';

    /** A numbered step in a sequence. The old template, used where it belongs. */
    case Step = 'step';

    /** One figure at display size. Guarded — see {@see needsEvidence()}. */
    case Stat = 'stat';

    /** This against that, as two halves rather than as a sentence. */
    case Contrast = 'contrast';

    /** A set, seen as a set. */
    case Checklist = 'checklist';

    /** The last slide, asking for exactly one thing. */
    case Cta = 'cta';

    /**
     * The composition id this renders through.
     *
     * Identical to the case value today, and a method anyway. The renderer's
     * composition names are its own vocabulary, and the day one is renamed or
     * two cases share a template, this is the one line that changes.
     */
    public function composition(): string
    {
        return $this->value;
    }

    /**
     * The fields the model must supply beyond `heading`, and their limits.
     *
     * `heading` is required of every slide whatever its layout, because it is
     * not only drawn: it becomes the panel's alt text and the slide's line in
     * the caption. A layout whose picture carries no heading still needs one for
     * the blind reader and for the person reading in a muted feed.
     *
     * @return array<string, int> field name => maximum length
     */
    public function fields(): array
    {
        return match ($this) {
            // `highlight` is a run of the heading drawn in the accent rather
            // than a second string: the two layouts that set type at 100px and
            // up are the only place a coloured word reads as emphasis instead
            // of as a mistake, and they are the two that otherwise put the
            // brand's accent nowhere near the words.
            self::Cover => ['kicker' => 60, 'highlight' => 80],
            self::Statement => ['highlight' => 80],
            self::Step => ['body' => 500],
            self::Stat => ['figure' => 12],
            self::Contrast => [
                'before' => 160,
                'after' => 160,
                'beforeLabel' => 40,
                'afterLabel' => 40,
            ],
            self::Checklist => [],
            self::Cta => ['body' => 300, 'action' => 40],
        };
    }

    /**
     * Every field's room, as one sentence for the model that fills them.
     *
     * Derived, because these numbers were enforced in one place and stated in
     * none: a writer given no budget for a button label wrote past it, and the
     * panel shipped reading "Save this guide before booking your regu". Two
     * copies of a number is the failure this codebase keeps finding, so the
     * prompt reads the same array the parser trims against.
     */
    public static function budgets(): string
    {
        $budgets = [];

        foreach (self::cases() as $layout) {
            foreach ($layout->fields() as $field => $limit) {
                // Keyed by field rather than by layout: `body` is 500 on a step
                // and 300 on a cta, and the tighter number is the safe one to
                // quote when one word covers both.
                $budgets[$field] = min($budgets[$field] ?? $limit, $limit);
            }
        }

        ksort($budgets);

        $parts = [];

        foreach ($budgets as $field => $limit) {
            $parts[] = "{$field} {$limit}";
        }

        return implode(', ', $parts).' characters.';
    }

    /**
     * Fields without which the layout cannot be drawn at all.
     *
     * Distinct from {@see fields()} because most extras are optional decoration
     * — a cover with no kicker is a cover — while a contrast with only one half
     * is not a contrast, it is a statement drawn in a template that will show an
     * empty coloured band where the other half should be.
     *
     * @return list<string>
     */
    public function required(): array
    {
        return match ($this) {
            self::Stat => ['figure'],
            self::Contrast => ['before', 'after'],
            self::Checklist => ['items'],
            self::Cover, self::Statement, self::Step, self::Cta => [],
        };
    }

    /**
     * Whether this layout may only state a figure the idea can already source.
     *
     * True for exactly one case, and it is the strongest slide in the set, which
     * is the problem. A number at 300px reads as established fact — it is the
     * most believable thing a carousel can show and therefore the most damaging
     * thing to invent. The engine already forbids inventing statistics in prose;
     * this is that rule reaching the one layout built to display them.
     *
     * A `stat` whose figure is not traceable degrades to {@see Statement} rather
     * than being dropped: the sentence was still worth writing, it simply may
     * not be presented as a measurement.
     */
    public function needsEvidence(): bool
    {
        return $this === self::Stat;
    }

    /**
     * Where a slide lands when its own layout will not do.
     *
     * Never null and never itself for the fallible cases: a slide that cannot be
     * drawn as what it asked to be is still a slide with a heading, and
     * {@see Statement} needs nothing but that. Dropping it instead would leave
     * the carousel a step short of the argument it was written to make.
     */
    public function fallback(): self
    {
        return $this === self::Statement ? self::Step : self::Statement;
    }

    /**
     * The layouts a slide in this position may be.
     *
     * The first slide is a cover and the last is a call to action, because that
     * is what makes the format work rather than a preference: the hook decides
     * whether anything after it is seen at all, and a carousel that ends without
     * asking for something has spent the attention it earned and banked nothing.
     * Neither belongs anywhere else — a cover in the middle is a second opening,
     * and a second call to action is a reader deciding to stop reading.
     *
     * @return list<self>
     */
    public static function allowedAt(int $position, int $count): array
    {
        if ($position === 1) {
            return [self::Cover];
        }

        if ($position === $count) {
            return [self::Cta];
        }

        return [self::Statement, self::Step, self::Stat, self::Contrast, self::Checklist];
    }
}
