<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\ChannelType;
use App\Enums\ContentFormat;
use App\Enums\PostKind;

/**
 * What a month has to be *made as*, as opposed to what each post is made as.
 *
 * The third time this argument has been made in this codebase, and the first
 * two are why it is an object rather than a sentence in a prompt.
 * {@see ContentMix} exists because no amount of checking one idea at a time
 * catches a month where every idea is fine and all twenty are how-tos.
 * `content_ideas.shot` moved to the planner because the drafts fan out in
 * parallel, so each briefs its photograph blind and thirty-three of forty
 * described a hand. Format is the same shape of problem: a model asked
 * per-idea which artefact fits will answer with its favourite, every time.
 *
 * It fails in both directions and both have been observed. Ours produced
 * **zero carousels across every month ever planned**, because nothing set
 * `content_format` and the fallback gives one only to a how-to. A competing
 * tool handed the same four ideas produced a five-slide carousel for all four.
 *
 * **Two denominators, which is why this is not a `shares` map.**
 * {@see ContentMix} can hold one because its kinds are mutually exclusive over
 * the same set. A carousel is Instagram-only ({@see ContentFormat::isAvailableOn()})
 * and {@see PostKind::channels()} keeps `take` off Instagram entirely, so
 * "about 7 carousels out of 21 ideas" is an instruction a quarter of the month
 * cannot obey — and a model handed an instruction it cannot satisfy resolves it
 * against whichever half it likes least. So the carousel share is counted
 * against the ideas that can carry one, the text share against all of them, and
 * a single image is what everything else is. Stated that way the arithmetic
 * cannot ask for more posts than the month has.
 *
 * **The instruction and the check are the same numbers**, the way they are in
 * {@see ContentMix}: {@see instruction()} is what the proposal prompt says and
 * {@see findings()} is what the normaliser enforces, both read off this object.
 */
final readonly class FormatMix
{
    private function __construct(
        private int $carouselShare,
        private int $textShare,
        private int $carouselCeiling,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            carouselShare: (int) config('content_studio.formats.carousel_share', 0),
            textShare: (int) config('content_studio.formats.text_share', 0),
            carouselCeiling: (int) config('content_studio.formats.carousel_ceiling', 100),
        );
    }

    /**
     * The ideas in this month that could carry a carousel at all.
     *
     * A property of the kind rather than of the idea, because the kind decides
     * the channels and Instagram decides whether a carousel is possible.
     *
     * @param  list<PostKind>  $kinds
     */
    public static function capable(array $kinds): int
    {
        return count(array_filter($kinds, static fn (PostKind $kind): bool => self::reachesInstagram($kind)));
    }

    /**
     * The same count, from the mix targets rather than from chosen kinds.
     *
     * The instruction is written before any kind exists, so it counts off
     * {@see ContentMix::targets()}; {@see findings()} counts off what the
     * planner actually chose. Both must mean the same thing, or a model gets
     * refused for producing the month it was asked for.
     *
     * @param  array<string, int>  $targets  kind value => how many
     */
    public static function capableInTargets(array $targets): int
    {
        $capable = 0;

        foreach ($targets as $value => $count) {
            $kind = PostKind::tryFromLoose($value);

            if ($kind !== null && self::reachesInstagram($kind)) {
                $capable += $count;
            }
        }

        return $capable;
    }

    /** How many carousels a month with this many carousel-capable ideas wants. */
    public function carouselTarget(int $capable): int
    {
        return intdiv($capable * $this->carouselShare, 100);
    }

    /** The most carousels it may have, which is a ceiling and not a target. */
    public function carouselLimit(int $capable): int
    {
        return intdiv($capable * $this->carouselCeiling, 100);
    }

    /** How many posts a month of this size wants with no picture at all. */
    public function textTarget(int $ideas): int
    {
        return intdiv($ideas * $this->textShare, 100);
    }

    /**
     * The format mix as a line in the proposal prompt.
     *
     * Counts rather than percentages, for the reason {@see ContentMix} gives:
     * the model is producing a list of that length and converting a percentage
     * into a count is something it does badly and silently.
     */
    public function instruction(int $ideas, int $capable): string
    {
        $carousels = $this->carouselTarget($capable);
        $texts = $this->textTarget($ideas);

        return implode(' ', array_filter([
            'A month is a mix of artefacts as well as of subjects, and the format is a decision about '
                .'what this idea contains — not about its kind. An idea with parts wants a carousel '
                .'whatever register it is in; an idea that is one thought wants one picture or none.',
            $capable < 1 || $carousels < 1
                ? null
                : "Of the {$capable} ideas that reach Instagram, aim for roughly {$carousels} carousels "
                    .'— the ones whose argument actually has steps, a comparison, a list or a run of '
                    .'figures in it. Do not make an idea into a carousel by padding one thought into '
                    .'five slides.',
            $texts < 1
                ? null
                : "About {$texts} of the {$ideas} want no picture at all: the opinions and questions "
                    .'where a photograph would be decoration.',
            'Everything else is a single image.',
            $capable < 1 ? null : sprintf(
                'At most %d of the %d may be a carousel — a ceiling, not a target. A feed of nothing '
                    .'but carousels is as monotonous as a feed of nothing but photographs.',
                $this->carouselLimit($capable),
                $capable,
            ),
        ]));
    }

    /**
     * What is wrong with this month's formats, in words the model can act on.
     *
     * Returned rather than thrown for the reason {@see ContentMix::findings()}
     * gives: the caller asks again with the finding attached, and an exception
     * would make the retry its problem to reconstruct.
     *
     * Two refusals, both failures of the set, and the omissions are deliberate.
     * A shortfall against the carousel target is not here — a month with two
     * instead of four is a month, the same latitude the kinds get. Nor is a
     * shortfall of text posts, because that path has never run in production
     * and a finding demanding one would make an untested artefact mandatory.
     *
     * @param  list<array{kind: PostKind, format: ContentFormat}>  $chosen
     * @return list<string>
     */
    public function findings(array $chosen): array
    {
        if ($chosen === []) {
            return [];
        }

        $capable = self::capable(array_column($chosen, 'kind'));
        $carousels = count(array_filter(
            $chosen,
            static fn (array $idea): bool => $idea['format'] === ContentFormat::Carousel,
        ));

        $findings = [];
        $limit = $this->carouselLimit($capable);

        if ($carousels > $limit) {
            $findings[] = sprintf(
                'The month carries %d carousels and at most %d are allowed out of the %d ideas that '
                    .'reach Instagram. Make the extra ones single images — an idea whose argument does '
                    .'not have parts is padded, not sequenced, when it is spread over five slides.',
                $carousels,
                $limit,
                $capable,
            );
        }

        if ($carousels === 0 && $this->carouselTarget($capable) >= 1) {
            $findings[] = sprintf(
                'The month contains no carousel at all, out of %d ideas that could carry one. The '
                    .'panels are drawn rather than generated, so they are the only artefact here whose '
                    .'words are legible — a checklist, a set of steps or a before-and-after belongs in '
                    .'one, and published as a single photograph it ends up described in the caption '
                    .'instead of shown.',
                $capable,
            );
        }

        return $findings;
    }

    private static function reachesInstagram(PostKind $kind): bool
    {
        return in_array(ChannelType::Instagram, $kind->channels(), true);
    }
}
