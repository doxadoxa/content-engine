<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Pipelines\Steps\SocialDraft\RankCandidates;
use App\Social\GovernorVerdict;
use Illuminate\Support\Str;
use Tests\Unit\Social\PostFormatTest;

/**
 * §2's format rules, as a number between 0 and 100.
 *
 * > Работает: вопрос, личное наблюдение с фактурой, картинка с короткой
 * > подписью. Не работает: тред-эссе (читается как лекция), хот-тейк без
 * > приглашения к ответу, самопродвижение без разговорной рамки, голая ссылка.
 * > Ссылки сами по себе больше не штрафуются, но ссылка, обёрнутая в мысль или
 * > вопрос, живёт лучше.
 *
 * The paragraph is a list of things that work and things that do not, and the
 * only way for {@see RankCandidates} to *use* it rather than mention it is to
 * turn it into arithmetic. Which is what this is: every clause above is one
 * constant below, and a candidate's score is the base plus what it earns minus
 * what it costs.
 *
 * **0–100, because that is the scale the bar is already on.** The governor's
 * `selection_floor` is 50 and its throttled twin is 64 — the 0–100 of
 * `SignalWeight`, which is what {@see GovernorVerdict::$selectionFloor} is
 * compared against everywhere else in this engine. A ranker with its own scale
 * would need a mapping, and a mapping is where "raise the bar" quietly stops
 * meaning anything. Sharing the scale is not enough on its own: the bar and the
 * bonuses have to be calibrated against each other or the shared scale merely
 * hides the mismatch, which is what happened at 70. See {@see QUESTION}.
 *
 * **Deterministic, and not because the guard is.** §4.3 only requires
 * `guard_policy` to be model-free. This is a separate argument: §4.3 also calls
 * `draft_candidates` "единственное место, где сознательно тратится дорогая
 * модель", and a ranker that asked a model would be a second one. Beyond the
 * cost, a selection bar that answers differently on two identical runs is not a
 * bar — the whole point of the throttled floor is that a weak week is
 * measurably pickier, and "measurably" needs a measurement.
 *
 * **Language-neutral on purpose.** This engine runs Portuguese, Russian and
 * English projects, and every signal read here survives that: a question mark
 * is a question mark, a digit is a figure, a URL is a URL, and a second segment
 * is a second segment. The alternative — keyword lists per language for
 * "self-promotion" and "hot take" — would score a project into silence the
 * first time somebody onboarded in a fourth language.
 */
final class PostFormat
{
    /**
     * A competent post with nothing for or against it.
     *
     * Exactly the ordinary selection floor, so a post that does nothing wrong
     * and nothing right is the marginal case in an ordinary week and is refused
     * outright in a throttled one. That is §4.3's "поднимает планку отбора"
     * with teeth: the throttled week does not publish the merely inoffensive.
     */
    public const int BASE = 50;

    /**
     * «Вопрос» — the format §2 puts first, and the invitation to reply.
     *
     * The first of the three bonuses below, one per shape §2 says works, and
     * the set matters more than the individual numbers: the throttled selection
     * floor is defined against it as `BASE + SUBSTANCE`, the smallest of the
     * three, so a weak week's bar means "be at least one of §2's working
     * shapes" rather than "be two of them at once". That is what «поднимает
     * планку отбора» has to mean to be a bar rather than a mute button. Before
     * {@see CAPTION} existed the three shapes topped out at 68, 64 and 50
     * against a floor of 70, so a throttled week accepted only a post that was
     * both a question and a figure and refused §2's own recommendations one by
     * one. The pairing is asserted in {@see PostFormatTest}.
     */
    public const int QUESTION = 18;

    /** «Личное наблюдение с фактурой» — a figure, a price, a date. */
    public const int SUBSTANCE = 14;

    /**
     * «Картинка с короткой подписью» — §2's third working shape.
     *
     * The one clause of that paragraph the scorer used to ignore completely,
     * which is how the shape §2 calls the *default* — "один пост, одна мысль, с
     * картинкой" — scored exactly BASE, the same as a post with nothing to
     * recommend it.
     *
     * Scored on the caption and not on the picture, because the picture is not
     * a distinguishing fact at this point in the pipeline: {@see
     * \App\Pipelines\Steps\SocialDraft\GenerateImage} illustrates whichever
     * candidate wins, so every post has one and a bonus for having one would be
     * a second name for BASE. What separates §2's third shape from the other
     * two is that the text is a caption — short enough to be read under a
     * picture rather than instead of it.
     *
     * Sized between the other two. §2's "картинки обходят текст примерно на
     * 60%" is a claim about the image, which every post gets; brevity is the
     * part this post has and the next one does not, and §2 lists the shape
     * third rather than first.
     */
    public const int CAPTION = 16;

    /**
     * Where a caption stops being one, in characters.
     *
     * About three sentences — the same shape {@see
     * \App\Pipelines\Steps\SocialDraft\DraftCandidates} asks the model for
     * ("two or three sentences at most") — and well under half the platform's
     * 500. It sits far below {@see LECTURE_CHARS} on purpose: the gap between
     * them is the ordinary post, neither a caption nor a lecture, which earns
     * nothing here and loses nothing.
     */
    public const int CAPTION_CHARS = 220;

    /**
     * «Тред-эссе (читается как лекция)», when nothing justified the chain.
     *
     * Large enough that a two-segment post with a question (50 + 18 − 25 = 43)
     * lands under the ordinary bar on its own. §2's default is one post, one
     * thought, and a default that a candidate can drift past by being slightly
     * better in another dimension is not a default.
     */
    public const int CHAIN_UNJUSTIFIED = 25;

    /**
     * The same chain, with the step's reason for it recorded.
     *
     * Reduced rather than waived. §2 calls a chain "исключение, которое шаг
     * обязан обосновать" — an exception is still worse than the default, so a
     * justified chain has to out-earn a single post rather than tie with it.
     */
    public const int CHAIN_JUSTIFIED = 10;

    /**
     * «Голая ссылка» — the one shape §2 names that has no redeeming version.
     *
     * A penalty and not a veto, even though the guard refuses a bare link
     * outright. Making it fatal here would only mean the guard's rule could
     * never fire — every bare link would be ranked out of the pool first, and a
     * refusal that is unreachable is a refusal nobody maintains. The two do
     * different jobs on the same clause: this one prefers the other seven
     * candidates, and the guard is what guarantees the bare link never ships
     * even when it is the only one left.
     */
    public const int BARE_LINK = 20;

    /**
     * «Самопродвижение без разговорной рамки», which is what an unframed link
     * is: something to click with no reason to answer.
     *
     * The mirror of §2's concession that a link inside a thought lives better.
     * The link itself costs nothing here — only its being alone with no
     * question does.
     */
    public const int UNFRAMED_LINK = 12;

    /** One segment long enough to read as a lecture rather than a remark. */
    public const int LECTURE = 12;

    /**
     * Where a post stops being a remark, in characters.
     *
     * Four fifths of the platform's 500, so it catches the post that fills the
     * box rather than the post that says something. §2's working shapes are a
     * question and a caption; neither of them needs 400 characters.
     */
    public const int LECTURE_CHARS = 400;

    /**
     * Absolute http(s) URLs only.
     *
     * Bare domains are deliberately not matched. "limescale.pt" in a sentence
     * about a competitor is not a link the reader can click and not a link the
     * platform will render, and treating it as one would refuse an honest post
     * for mentioning a name.
     */
    private const string URL_PATTERN = '#https?://\S+#iu';

    /**
     * A standalone four-digit year, which {@see hasSubstance()} does not count.
     *
     * Bounded by non-digits on both sides so that a price of 2026 euros in a
     * longer number, a phone number or "1200 litres" is untouched — only a
     * number that stands alone and reads as a year is removed.
     */
    private const string YEAR_PATTERN = '/(?<!\d)(?:19|20|21)\d{2}(?!\d)/u';

    /**
     * What this candidate is worth, on the governor's scale.
     *
     * @param  list<string>  $segments
     * @param  string|null  $link  the attachment, if the candidate carries one
     * @param  bool  $chainJustified  whether a chain came with a stated reason
     */
    public static function score(array $segments, ?string $link = null, bool $chainJustified = false): int
    {
        $text = trim(implode("\n\n", $segments));

        if ($text === '') {
            return 0;
        }

        $score = self::BASE;

        if (self::invitesAReply($text)) {
            $score += self::QUESTION;
        }

        if (self::hasSubstance($text)) {
            $score += self::SUBSTANCE;
        }

        if (self::isCaption($segments, $link)) {
            $score += self::CAPTION;
        }

        if (count($segments) > 1) {
            $score -= $chainJustified ? self::CHAIN_JUSTIFIED : self::CHAIN_UNJUSTIFIED;
        }

        if (self::isBareLink($text, $link)) {
            $score -= self::BARE_LINK;
        } elseif (self::hasLink($text, $link) && ! self::invitesAReply($text)) {
            $score -= self::UNFRAMED_LINK;
        }

        foreach ($segments as $segment) {
            if (mb_strlen(trim($segment)) > self::LECTURE_CHARS) {
                $score -= self::LECTURE;

                break;
            }
        }

        return max(0, min(100, $score));
    }

    /**
     * Whether there is a question in it at all.
     *
     * Punctuation and not phrasing, because phrasing is a language list and
     * punctuation is not. It reads the whole post rather than only the last
     * segment: a question in the opening line is still an invitation, and §1's
     * first fact is that the algorithm weighs replies rather than where the
     * question mark sat.
     */
    public static function invitesAReply(string $text): bool
    {
        return str_contains($text, '?') || str_contains($text, '？');
    }

    /**
     * §2's third shape: one thought, short enough to sit under a picture.
     *
     * Three conditions, and each one is a clause of the spec rather than a
     * preference. One segment, because a caption for a photograph is not a
     * chain and §2's default is "один пост, одна мысль". No URL, because a post
     * pointing somewhere is a link post — §2 scores that separately, and
     * without this condition the bonus would land on «голая ссылка», which is
     * short by definition and is the one shape §2 says has no working version.
     * And short, which is the whole of it.
     *
     * @param  list<string>  $segments
     */
    public static function isCaption(array $segments, ?string $link = null): bool
    {
        if (count($segments) !== 1) {
            return false;
        }

        $text = trim($segments[0]);

        if ($text === '' || self::hasLink($text, $link)) {
            return false;
        }

        return mb_strlen($text) <= self::CAPTION_CHARS;
    }

    /**
     * Whether the post carries anything a reader could check.
     *
     * A digit, which is the deterministic half of «фактура»: a price, a
     * percentage, a count. It is a proxy and it is a coarse one — a post can be
     * full of substance and have no numbers in it — but it is a proxy that
     * never disagrees with itself, and the failure mode is a good post scoring
     * 14 lower rather than a bad post scoring higher.
     *
     * Read with the URLs cut out. A tracking parameter, a year in a slug and an
     * article id are all digits, and counting them would hand the bare link the
     * substance bonus for having a long address — which is the exact opposite
     * of what this clause of §2 is for.
     *
     * **A bare year is cut out too, and that is a narrowing of what this used
     * to mean.** «2026» on its own is not фактура; it is the sort of digit a
     * hot take contains for free, and §2 lists the uninvited hot take among the
     * shapes that do not work. Cutting it costs nothing real, because a post
     * that actually carries a date carries more than the year — "8 августа
     * 2026", "March 3rd", "on the 14th" all keep a digit after the years are
     * removed — and a post whose only number is a year was never the personal
     * observation with a figure that §2 is describing.
     *
     * The carve-out is a range and not a word list on purpose. This engine runs
     * pt/ru/en and will run a fourth language; 1900–2199 is the same four
     * characters in all of them.
     */
    public static function hasSubstance(string $text): bool
    {
        $stripped = (string) preg_replace(self::URL_PATTERN, ' ', $text);
        $stripped = (string) preg_replace(self::YEAR_PATTERN, ' ', $stripped);

        return preg_match('/\d/u', $stripped) === 1;
    }

    /** Whether there is a URL in the text or hanging off the post. */
    public static function hasLink(string $text, ?string $link = null): bool
    {
        return $link !== null || self::urlsIn($text) !== [];
    }

    /**
     * «Голая ссылка»: a URL and not enough else to be a thought.
     *
     * Measured in words rather than characters, and measured on the text with
     * the URLs removed — a long URL is not a long post, and counting it would
     * let a tracking query string pass for a sentence.
     */
    public static function isBareLink(string $text, ?string $link = null): bool
    {
        if (! self::hasLink($text, $link)) {
            return false;
        }

        return self::wordsBesidesLinks($text) < self::linkMinWords();
    }

    /** How much of a thought there is around the links. */
    public static function wordsBesidesLinks(string $text): int
    {
        $stripped = trim((string) preg_replace(self::URL_PATTERN, ' ', $text));

        if ($stripped === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $stripped) ?: []);
    }

    /**
     * Every URL in the text, in the order they appear.
     *
     * @return list<string>
     */
    public static function urlsIn(string $text): array
    {
        preg_match_all(self::URL_PATTERN, $text, $matches);

        /** @var list<string> $urls */
        $urls = $matches[0];

        return $urls;
    }

    /** The first URL the post points at, wherever it came from. */
    public static function firstUrl(string $text, ?string $link = null): ?string
    {
        if ($link !== null && Str::startsWith($link, ['http://', 'https://'])) {
            return $link;
        }

        return self::urlsIn($text)[0] ?? $link;
    }

    private static function linkMinWords(): int
    {
        $value = config('social.draft.link_min_words', 10);

        return is_numeric($value) ? (int) $value : 10;
    }
}
