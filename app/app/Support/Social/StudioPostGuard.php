<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Models\BrandBrief;
use App\Pipelines\Steps\SocialDraft\GuardFinding;
use App\Pipelines\Steps\SocialDraft\GuardPolicy;

/**
 * §4.3's deterministic refusals, asked of a Studio draft.
 *
 * {@see GuardPolicy} runs these for the Threads contour and has since §4.3 was
 * written. The Studio ran none of them: it asked a model to stay inside a
 * character limit and then wrote whatever came back into a reviewable draft.
 * A model told to stay under 280 characters is a hope, and the difference
 * between a hope and a gate is what this file is.
 *
 * **Not a copy of {@see GuardPolicy}, and not a refactor of it either.** The
 * two answer the same questions and there was a real temptation to make one
 * class serve both, but they consume different things — that one reads a
 * pipeline `StepContext` and a slot payload, this one reads a channel and a
 * brief — and collapsing them would mean giving the Threads contour a
 * dependency on the Studio's shapes to save a hundred lines. What they *do*
 * share is the part that matters for §7: the finding codes are
 * {@see GuardFinding}'s own, so an operator's summary groups a Studio refusal
 * and a contour refusal under one heading instead of two spellings of it.
 *
 * **Channel-aware, because the rules are.** A 400-character post is over the
 * line on X, ordinary on Threads and terse on Instagram. The limits come from
 * {@see ChannelPlaybook} so that the number the prompt asked for and the number
 * the guard enforces cannot disagree.
 *
 * **Every finding is collected, none short-circuits.** Same reason as the
 * contour's: an operator reading a refused draft has to see everything wrong
 * with it at once, not the first thing wrong with it followed by a re-run.
 */
final class StudioPostGuard
{
    /**
     * Everything that would stop this candidate being published as written.
     *
     * `$panels` is a carousel's slide text. It is checked for the things that
     * are about *words going out under the brand's name* — a forbidden topic,
     * whether the post names anything of this project's — and deliberately not
     * for the things that are about what the platform will accept: a slide is
     * not a segment, and counting it as one would refuse every carousel for
     * being a chain.
     *
     * **§4.3's entity resolution is deliberately not here**, though it is one of
     * the five refusals and {@see GuardPolicy} applies it. It belongs to the
     * contour and not to this one, and the difference is what the post descends
     * from. A native post is written from a signal and has nothing tying it to
     * this project, so "names something the corpus knows about" is the tie. A
     * Studio draft descends from an approved `ContentIdea` with a thesis an
     * operator accepted — the tie was established a step earlier, by a human.
     *
     * Applying it anyway did active harm, which is how it was found. A real
     * project's vocabulary is what {@see ProjectVocabulary} derives from its
     * corpus: long-tail search phrases like "limpeza pós obra preço por metro
     * quadrado". No conversational English post contains one, so every natural
     * candidate was refused and the only ones surviving were the ones that had
     * shoehorned in a line of service copy — the guard selecting for
     * «самопродвижение без разговорной рамки», the shape §2 names as one that
     * does not work. A rule that makes the output worse is not a rule that was
     * merely mis-tuned.
     *
     * @param  list<string>  $segments
     * @return list<GuardFinding>
     */
    public static function check(
        ChannelPlaybook $playbook,
        array $segments,
        ?string $link = null,
        bool $chainJustified = false,
        ?BrandBrief $brief = null,
        string $panels = '',
    ): array {
        $text = trim(implode("\n\n", $segments));

        if ($text === '') {
            return [new GuardFinding(
                GuardFinding::BLANK,
                'The candidate has no text in it, so there is no post to publish.',
            )];
        }

        $written = trim($text."\n\n".$panels);

        return [
            ...self::lengthFindings($playbook, $segments),
            ...self::chainFindings($playbook, $segments, $chainJustified),
            ...self::forbiddenFindings($written, $brief),
            ...self::linkFindings($text, $link),
        ];
    }

    /**
     * The platform's own limit, per segment.
     *
     * Per segment rather than over the whole post, because that is how every
     * one of these APIs counts: a thread of three is three posts of 280, not
     * one of 840.
     *
     * @param  list<string>  $segments
     * @return list<GuardFinding>
     */
    private static function lengthFindings(ChannelPlaybook $playbook, array $segments): array
    {
        $findings = [];

        foreach ($segments as $position => $segment) {
            $length = mb_strlen(trim($segment));

            if ($length <= $playbook->segmentLimit) {
                continue;
            }

            $findings[] = new GuardFinding(
                GuardFinding::LENGTH,
                sprintf(
                    'Segment %d is %d characters and %s refuses anything over %d, so the post could not '
                        .'be published as written.',
                    $position + 1,
                    $length,
                    $playbook->channel->label(),
                    $playbook->segmentLimit,
                ),
            );
        }

        return $findings;
    }

    /**
     * How many segments there may be, and whether a second one was argued for.
     *
     * Two different questions, as in the contour. The ceiling is what the
     * channel's native thread shape tops out at; the justification is §2's
     * "цепочка — исключение, которое шаг обязан обосновать", and it holds on X
     * as well as on Threads for the reason the platform writers keep repeating:
     * a thread is not a way to get more room for an idea that fitted.
     *
     * @param  list<string>  $segments
     * @return list<GuardFinding>
     */
    private static function chainFindings(
        ChannelPlaybook $playbook,
        array $segments,
        bool $chainJustified,
    ): array {
        $count = count($segments);
        $findings = [];

        if ($count > $playbook->maxSegments) {
            $findings[] = new GuardFinding(
                GuardFinding::SEGMENT_COUNT,
                sprintf(
                    'The post is %d segments and %s takes at most %d — past that it reads as the '
                        .'thread-essay that does not work on any of these channels.',
                    $count,
                    $playbook->channel->label(),
                    $playbook->maxSegments,
                ),
            );
        }

        if ($count > 1 && ! $chainJustified) {
            $findings[] = new GuardFinding(
                GuardFinding::UNJUSTIFIED_CHAIN,
                'The post is a chain with no reason given for being one. The default is one post, one '
                    .'thought, and a chain is an exception that has to be argued for.',
            );
        }

        return $findings;
    }

    /**
     * Topics the Brand Brief says this project never writes about.
     *
     * Whole words and `\p{L}` rather than `\b`, for the reason
     * {@see GuardPolicy} gives: a brief that forbids "ICO" must not match
     * "medico" and refuse every post, and `\b` is ASCII-shaped even under `/u`,
     * which matters on the Cyrillic and Portuguese briefs this engine runs.
     *
     * @return list<GuardFinding>
     */
    private static function forbiddenFindings(string $text, ?BrandBrief $brief): array
    {
        $findings = [];

        foreach ($brief === null ? [] : $brief->forbidden_topics as $topic) {
            $needle = trim($topic);

            if ($needle === '') {
                continue;
            }

            if (preg_match('/(?<!\p{L})'.preg_quote($needle, '/').'(?!\p{L})/iu', $text) !== 1) {
                continue;
            }

            $findings[] = new GuardFinding(
                GuardFinding::FORBIDDEN_TOPIC,
                "The post mentions “{$needle}”, which the Brand Brief says this project never writes about.",
            );
        }

        return $findings;
    }

    /**
     * The link policy, which is about the shape around the link.
     *
     * The same three rules the contour applies, and the same argument for them:
     * links are not penalised in themselves, a bare link is the one format with
     * no redeeming version, anything that is not http(s) is not something this
     * engine posts under a brand's name, and two links is a dump rather than a
     * thought with a reference in it.
     *
     * @return list<GuardFinding>
     */
    private static function linkFindings(string $text, ?string $link): array
    {
        $findings = [];
        $urls = PostFormat::urlsIn($text);

        if ($link !== null && ! preg_match('#^https?://#i', $link)) {
            $findings[] = new GuardFinding(
                GuardFinding::LINK_POLICY,
                "The post's link “{$link}” is not an http or https URL, so it is not something a reader "
                    .'could follow.',
            );
        }

        if (count(array_unique($urls)) > 1) {
            $findings[] = new GuardFinding(
                GuardFinding::LINK_POLICY,
                sprintf(
                    'The post carries %d links. One thought points at one thing; more than that is a '
                        .'link dump with no conversational frame.',
                    count(array_unique($urls)),
                ),
            );
        }

        if (PostFormat::isBareLink($text, $link)) {
            $findings[] = new GuardFinding(
                GuardFinding::BARE_LINK,
                sprintf(
                    'The post is a bare link: %d words besides the URL. Wrap it in a thought or a question.',
                    PostFormat::wordsBesidesLinks($text),
                ),
            );
        }

        return $findings;
    }
}
