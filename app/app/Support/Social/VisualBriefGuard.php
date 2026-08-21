<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\PostKind;
use App\Pipelines\Steps\SocialDraft\GuardFinding;

/**
 * The three ways a photograph brief comes back describing nothing.
 *
 * {@see SocialImagePrompt} argues that six named fields beat one sentence, and
 * they do — but a field can be specific and still be empty. Every complaint in
 * the review of the first month's pictures reduced to one of these:
 *
 *   1. **A prop instead of a subject.** The post is about a standard, a
 *      checklist, a decision; none of those has a photograph, so the model
 *      briefs the object that represents one. Told it may not ask for text, it
 *      asks for the object without it — "a checklist-style clipboard with no
 *      legible writing" — and an empty form photographs as an unfinished one.
 *   2. **Anticipation instead of action.** "Paused before wiping", "as if
 *      preparing to". The frame is a person about to do something, which is the
 *      one moment in the work with no evidence in it.
 *   3. **No work at all.** A hand setting down a mug on a clean counter. It is
 *      a competent photograph of nothing, and it is what an abstract thesis
 *      produces when the brief is written from the thesis rather than from the
 *      work behind it.
 *
 * **Corrections, not refusals.** These come back as sentences for the model
 * rather than as {@see GuardFinding}s, and the
 * difference is deliberate: a guard finding is blocking, and blocking a post
 * whose *words* are good because its picture brief is weak throws away the
 * expensive half to fix the cheap one. The writer is asked again, once, with
 * the reason — the same machinery a malformed JSON answer already uses.
 *
 * **Deterministic and narrow on purpose.** A model asked to judge "is this
 * picture worth taking" answers yes, and the whole point of these three is that
 * they are the cases where nobody had to judge. Anything subtler than a word
 * list belongs to the person reviewing the draft.
 */
final class VisualBriefGuard
{
    /**
     * Objects that exist to carry words.
     *
     * A cleaning brand has one honest use of the word "tablet" — the thing that
     * goes in a dishwasher — so that reading is excluded rather than the word,
     * which is the difference between a rule and a nuisance.
     */
    private const array TEXT_PROPS = [
        'clipboard', 'checklist', 'check list', 'notebook', 'notepad', 'paperwork', 'document',
        'receipt', 'invoice', 'questionnaire', 'signage', 'poster', 'sticker', 'label', 'form',
        'phone', 'screen', 'laptop', 'ipad', 'monitor', 'interface', 'app',
    ];

    /**
     * The same, where a narrower reading of the word is legitimate here.
     *
     * Only words a whole-word match cannot separate on its own need to be here.
     * "Perform" and "uniform" do not: the `\p{L}` boundary in {@see mentions()}
     * already refuses to find "form" inside them. A dishwasher tablet is a real
     * second word, so it needs a real second rule.
     *
     * @var array<string, list<string>>
     */
    private const array TEXT_PROPS_EXCEPT = [
        'tablet' => ['dishwasher', 'detergent', 'cleaning', 'soap'],
    ];

    /**
     * A frame held at the moment before anything happens.
     *
     * Phrases, not words, and every one of them has to be unambiguously about
     * time. "Is about" and "as though" were here and had to go: a brief saying
     * the composition *is about* the contrast, or that a sill looks *as though*
     * nobody has touched it in weeks, is describing evidence — the second one
     * is describing exactly the evidence this guard exists to ask for.
     */
    private const array ANTICIPATION = [
        'about to', 'paused before', 'pausing before', 'poised', 'as if preparing', 'preparing to',
        'ready to', 'just before', 'moments before', 'reaching for',
    ];

    /**
     * Evidence that work is happening, in the words a brief actually uses.
     *
     * Three families, any one of which is enough: what the work leaves behind,
     * what the work is, and what it left. The third is there for
     * {@see PostKind::Proof}, whose shot may legitimately be the *after* — a
     * surface with nothing on it but the light — and which the first two
     * families would refuse for being clean.
     *
     * Stems rather than words: "wipe" has to answer for "wipes", "wiping" and
     * "wiped", and listing the conjugations is how a list like this goes stale.
     */
    private const array WORK_STEMS = [
        'residue', 'limescale', 'grime', 'dust', 'dirt', 'grease', 'soap', 'foam', 'lather', 'suds',
        'stain', 'smear', 'streak', 'smudge', 'crumb', 'spill', 'splash', 'dirty', 'soiled', 'grubby',
        'scuff', 'watermark', 'mould', 'mildew', 'debris', 'lint', 'hair', 'fingerprint',
        'wipe', 'scrub', 'brush', 'sweep', 'mop', 'rinse', 'scrape', 'polish', 'buff', 'dry',
        'vacuum', 'wring', 'lift', 'wash', 'spray', 'dissolve', 'loosen', 'peel', 'strip',
        'cleaned', 'cleaning', 'freshly', 'gleam', 'shine', 'reflect', 'spotless',
    ];

    /**
     * Everything wrong with this brief, in sentences the writer can act on.
     *
     * @param  array<string, mixed>  $visual
     * @return list<string>
     */
    public static function check(array $visual, PostKind $kind): array
    {
        $fields = self::readable($visual);

        if ($fields === '') {
            return [];
        }

        return array_values(array_filter([
            self::propComplaint($fields),
            self::anticipationComplaint($fields),
            self::emptinessComplaint($fields, $kind),
        ]));
    }

    /**
     * The fields that describe what is in the frame, as one lowercase haystack.
     *
     * `style` and `light` are left out deliberately: they say how the picture
     * is made, not what is in it, and "documentary" is not evidence of work.
     *
     * @param  array<string, mixed>  $visual
     */
    private static function readable(array $visual): string
    {
        $parts = [];

        foreach (['subject', 'composition', 'action', 'location'] as $field) {
            $value = $visual[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }

        return mb_strtolower(implode(' ', $parts));
    }

    private static function propComplaint(string $fields): ?string
    {
        $found = [];

        foreach (self::TEXT_PROPS as $prop) {
            if (self::mentions($fields, $prop)) {
                $found[] = $prop;
            }
        }

        foreach (self::TEXT_PROPS_EXCEPT as $prop => $exceptions) {
            if (self::mentions($fields, $prop) && ! self::qualifiedBy($fields, $prop, $exceptions)) {
                $found[] = $prop;
            }
        }

        if ($found === []) {
            return null;
        }

        return sprintf(
            'The photograph is briefed around %s. An object whose purpose is to carry words cannot be '
                .'photographed without them — asking for a blank one produces a picture of something '
                .'nobody filled in. Brief the work the %s stands for instead.',
            self::listed($found),
            $found[0],
        );
    }

    private static function anticipationComplaint(string $fields): ?string
    {
        foreach (self::ANTICIPATION as $phrase) {
            if (! str_contains($fields, $phrase)) {
                continue;
            }

            return "The photograph is briefed at the moment before the work — “{$phrase}”. That frame has "
                .'no evidence in it. Brief the contact itself: the tool on the surface, the change '
                .'happening.';
        }

        return null;
    }

    /**
     * A brief with no work in it, which an `offer` is allowed to be.
     *
     * {@see PostKind::Offer} asks for the finished state — the outcome somebody
     * is buying — and a finished state has by definition no dirt in it and
     * nobody scrubbing. Applying this rule to it would refuse the one kind
     * whose picture is supposed to be clean.
     */
    private static function emptinessComplaint(string $fields, PostKind $kind): ?string
    {
        if ($kind === PostKind::Offer) {
            return null;
        }

        foreach (self::WORK_STEMS as $stem) {
            if (self::mentionsStem($fields, $stem)) {
                return null;
            }
        }

        return 'Nothing is happening in this photograph. It describes objects and a place, but not the '
            .'work, what the work is removing, or what it has changed — so there is nothing in the frame '
            .'worth stopping on. Name the residue, the contact or the difference.';
    }

    /** Whole word, `\p{L}`-bounded for the reason {@see StudioPostGuard} gives. */
    private static function mentions(string $fields, string $needle): bool
    {
        return preg_match('/(?<!\p{L})'.preg_quote($needle, '/').'(?!\p{L})/u', $fields) === 1;
    }

    /**
     * The same word, but only where one of these shortly precedes it.
     *
     * @param  list<string>  $qualifiers
     */
    private static function qualifiedBy(string $fields, string $needle, array $qualifiers): bool
    {
        foreach ($qualifiers as $qualifier) {
            $pattern = '/'.preg_quote($qualifier, '/').'[\p{L}\s-]{0,12}'.preg_quote($needle, '/').'/u';

            if (preg_match($pattern, $fields) === 1) {
                return true;
            }
        }

        return false;
    }

    /** A stem and whatever it was conjugated into: `wipe` finds `wiping`. */
    private static function mentionsStem(string $fields, string $stem): bool
    {
        return preg_match('/(?<!\p{L})'.preg_quote($stem, '/').'\p{L}{0,4}(?!\p{L})/u', $fields) === 1;
    }

    /** @param  list<string>  $items */
    private static function listed(array $items): string
    {
        $quoted = array_map(static fn (string $item): string => "“{$item}”", array_unique($items));

        if (count($quoted) === 1) {
            return $quoted[0];
        }

        $last = array_pop($quoted);

        return implode(', ', $quoted).' and '.$last;
    }
}
