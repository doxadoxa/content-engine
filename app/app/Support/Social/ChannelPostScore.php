<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\ContentStudio\ContentStudioAssistant;
use App\Pipelines\Steps\SocialDraft\RankCandidates;

/**
 * {@see PostFormat}'s arithmetic, asked of a channel that is not Threads.
 *
 * The Studio writes for three channels and had no bar on any of them: whatever
 * the model returned first was the draft. {@see RankCandidates} already solved
 * this for Threads — write several, score them, keep one, and leave the slot
 * empty rather than take the least bad — and the only reason that machinery
 * could not simply be pointed at the Studio is that its scale is Threads-shaped.
 * A 900-character Instagram caption is not a lecture; on Threads a
 * 900-character post is exactly the тред-эссе §2 refuses.
 *
 * So the weights are {@see PostFormat}'s, imported rather than restated, and
 * only the thresholds move. That is the whole design and it is what keeps the
 * two scorers from drifting: change what a question is worth and both change.
 * On Threads the thresholds resolve to {@see PostFormat}'s own — 220 characters
 * for a caption, 400 for a lecture, {@see PostFormat::QUESTION} for a question —
 * so the two return the same number for any post that carries neither of the two
 * penalties added below. The suite asserts that rather than trusting it. Off
 * Threads the question is worth what that channel pays for it; see
 * {@see ChannelPlaybook::$questionBonus}.
 *
 * Two penalties exist here that {@see PostFormat} has no reason to carry, both
 * of them things the Studio's own output was doing:
 *
 *   - **hashtags past what the channel rewards.** Zero on Threads, one on X.
 *     Language-neutral — a `#` followed by word characters is a hashtag in
 *     every locale this engine runs.
 *   - **assistant filler.** The stock phrases that make a post read as
 *     generated. See {@see FILLER}, and read its docblock before adding to it.
 *
 * And one bonus: {@see hooksBeforeTheFold()}, for the caption channels where
 * the first line is the only line most people see.
 */
final class ChannelPostScore
{
    /** What a hashtag past the channel's ceiling costs, each. */
    public const int HASHTAG_OVER_CEILING = 8;

    /** One stock assistant phrase, and it only needs to be there once. */
    public const int FILLER = 15;

    /** A caption whose first line does the work of a first line. */
    public const int HOOK = 10;

    /**
     * Where Instagram cuts a caption off in the feed.
     *
     * Approximate and the platform's rather than ours — it varies with the
     * viewport — which is why the bonus is for landing inside it rather than a
     * penalty for missing it.
     */
    public const int FOLD_CHARS = 125;

    /**
     * The phrases that make a reader stop believing a person wrote it.
     *
     * **English only, and that is a deliberate asymmetry rather than an
     * oversight.** {@see PostFormat} argues at length that a scorer this engine
     * runs on Portuguese, Russian and English projects must not depend on word
     * lists, and it is right — about *bonuses* and about anything that can
     * refuse a post. This is neither: it is a penalty, it fires only on an
     * exact stock phrase, and the worst it can do to a project writing in a
     * language not listed here is nothing at all. A Russian post with Russian
     * filler scores as though the filler were not there, which is where every
     * post stood before this constant existed. Nothing gets worse; English gets
     * better. Add a language by adding its phrases, not by removing this note.
     *
     * Matched case-insensitively as substrings, because these are phrases
     * rather than words and a word-boundary match would miss "It's no secret
     * that," with its comma. The text is normalised first — models emit the
     * typographic apostrophe U+2019 far more often than the ASCII one, and a
     * list written with `'` would have missed every phrase that contains one,
     * which is most of the ones worth catching.
     *
     * **Each entry has to be unusable as ordinary writing.** The penalty is 15
     * points, enough on its own to drop a candidate under the bar and reorder
     * the pool, so a phrase that a person might legitimately write costs a good
     * post its place. "Take your" and a bare "dive into" were both in this list
     * and both fail that test — "take your first ten customers seriously" and
     * "we had to dive into the logs" are things somebody writes on purpose.
     * They are gone. What is left is only the stock construction.
     *
     * @var list<string>
     */
    private const array FILLER_PHRASES = [
        'in today\'s fast-paced',
        'in the world of',
        'let\'s dive in',
        'game-changer',
        'game changer',
        'it\'s no secret',
        'we\'re excited to share',
        'i\'m excited to share',
        'excited to announce',
        'in an era where',
        'the key takeaway',
        'unlock the power',
        'to the next level',
    ];

    /** A `#` that starts a word, in any script. */
    private const string HASHTAG_PATTERN = '/(?<![\p{L}\p{N}])#[\p{L}\p{N}_]+/u';

    /**
     * What this candidate is worth on this channel, on the governor's 0–100.
     *
     * @param  list<string>  $segments
     * @param  string|null  $link  the attachment, if the candidate carries one
     * @param  bool  $chainJustified  whether a chain came with a stated reason
     */
    public static function score(
        ChannelPlaybook $playbook,
        array $segments,
        ?string $link = null,
        bool $chainJustified = false,
    ): int {
        $text = trim(implode("\n\n", $segments));

        if ($text === '') {
            return 0;
        }

        $score = PostFormat::BASE;

        if (PostFormat::invitesAReply($text)) {
            // The channel's own number. A question is worth what the platform
            // pays for it, and the platforms disagree: Threads is measured on
            // replies, X on whether the line stands up by itself.
            $score += $playbook->questionBonus;
        }

        if (PostFormat::hasSubstance($text)) {
            $score += PostFormat::SUBSTANCE;
        }

        if (self::isConcise($playbook, $segments, $link)) {
            $score += PostFormat::CAPTION;
        }

        if ($playbook->isCaptionChannel() && self::hooksBeforeTheFold($text)) {
            $score += self::HOOK;
        }

        if (count($segments) > 1) {
            $score -= $chainJustified ? PostFormat::CHAIN_JUSTIFIED : PostFormat::CHAIN_UNJUSTIFIED;
        }

        if (PostFormat::isBareLink($text, $link)) {
            $score -= PostFormat::BARE_LINK;
        } elseif (PostFormat::hasLink($text, $link) && ! PostFormat::invitesAReply($text)) {
            $score -= PostFormat::UNFRAMED_LINK;
        }

        $score -= self::hashtagPenalty($playbook, $text);

        if (self::hasFiller($text)) {
            $score -= self::FILLER;
        }

        foreach ($segments as $segment) {
            if (mb_strlen(trim($segment)) > self::lectureChars($playbook)) {
                $score -= PostFormat::LECTURE;

                break;
            }
        }

        return max(0, min(100, $score));
    }

    /**
     * Whether the first line earns the tap that reveals the rest.
     *
     * Two conditions and both are the platform's rather than a preference: the
     * line has to survive the fold, and it has to be a line rather than the
     * opening clause of a paragraph that runs past it. A caption written as one
     * unbroken block has no first line in the sense that matters — the reader
     * sees 125 characters of a sentence and a "more" link.
     */
    public static function hooksBeforeTheFold(string $text): bool
    {
        $first = trim((string) (preg_split('/\R/u', trim($text)) ?: [''])[0]);

        if ($first === '') {
            return false;
        }

        return mb_strlen($first) <= self::FOLD_CHARS;
    }

    /** Whether one of the stock assistant phrases is in it. */
    public static function hasFiller(string $text): bool
    {
        $haystack = mb_strtolower(str_replace(['’', '‘', '＇'], "'", $text));

        foreach (self::FILLER_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /** How many hashtags the post carries. */
    public static function hashtagCount(string $text): int
    {
        return (int) preg_match_all(self::HASHTAG_PATTERN, $text);
    }

    /**
     * §2's third shape, measured against this channel's idea of short.
     *
     * The same three conditions {@see PostFormat::isCaption()} applies — one
     * segment, no URL, short — with "short" coming from the playbook. On
     * Threads that is {@see PostFormat::CAPTION_CHARS} and the two agree
     * exactly; on Instagram it is most of a caption, because a caption that
     * says its thing in 1 200 characters instead of 2 200 is the concise one
     * there.
     *
     * @param  list<string>  $segments
     */
    private static function isConcise(ChannelPlaybook $playbook, array $segments, ?string $link): bool
    {
        if (count($segments) !== 1) {
            return false;
        }

        $text = trim($segments[0]);

        if ($text === '' || PostFormat::hasLink($text, $link)) {
            return false;
        }

        return mb_strlen($text) <= $playbook->idealChars;
    }

    /**
     * Where a post stops being a remark on this channel.
     *
     * Four fifths of what the channel will accept, which is the ratio
     * {@see PostFormat::LECTURE_CHARS} is to the Threads limit — so Threads
     * lands on 400 and keeps agreeing with the older scorer, and every other
     * channel gets the same rule rather than the same number.
     */
    private static function lectureChars(ChannelPlaybook $playbook): int
    {
        return (int) ($playbook->segmentLimit * 0.8);
    }

    /**
     * What the hashtags past the ceiling cost.
     *
     * Per hashtag over, not a flat penalty, because the failure this catches is
     * a tail of twenty of them and a flat penalty would price that the same as
     * one stray tag. Uncapped, because {@see score()} already clamps at zero
     * and a post carrying a wall of tags has nothing left to distinguish it
     * from another one — {@see ContentStudioAssistant} is choosing between
     * candidates, and both of those lose to anything.
     */
    private static function hashtagPenalty(ChannelPlaybook $playbook, string $text): int
    {
        $over = self::hashtagCount($text) - $playbook->hashtagCeiling;

        return $over <= 0 ? 0 : $over * self::HASHTAG_OVER_CEILING;
    }
}
