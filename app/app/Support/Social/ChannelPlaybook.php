<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\ContentStudio\ContentStudioAssistant;
use App\Enums\ChannelType;
use App\Pipelines\Steps\SocialDraft\DraftCandidates;

/**
 * What one channel will actually reward, as instructions rather than as limits.
 *
 * The Studio used to describe a channel to the model in one clause: how many
 * characters it takes. That is the part a channel shares with every other
 * channel — a wall the copy has to fit inside — and none of the part that makes
 * a post native to it. A model told "Threads: at most 500 characters, X: at
 * most 280" writes the same post twice and trims the second one, which is
 * exactly the cross-posting {@see ChannelType::takesArticleDerivatives()}
 * refuses for Threads and exactly what an operator recognises on sight.
 *
 * So every channel below carries four things the limit does not: the shapes
 * that work on it, the shapes that do not, how long a post wants to be as
 * opposed to how long it may be, and what its picture is shaped like. The
 * numbers here are the platforms'; the sentences are editorial positions, and
 * they are stated as positions so a future reader can disagree with one
 * deliberately rather than discover it by reading a prompt string.
 *
 * **Threads rewards a take, X rewards a standalone line, Instagram rewards a
 * first line worth tapping "more" for.** Those three sentences are the whole
 * file. Everything else is them written out far enough that a model cannot
 * satisfy them by accident.
 *
 * **Read by three callers who must not disagree.** {@see ContentStudioAssistant}
 * prompts with it, {@see ChannelPostScore} scores against it, and
 * {@see StudioPostGuard} refuses against it. Before this existed the character
 * limit was written down in the prompt, in the normaliser and in the guard
 * separately, and the three had already drifted: the prompt asked for at most
 * three Threads segments while the normaliser accepted three and the spec's own
 * ceiling was elsewhere in config. One object, three readers.
 *
 * A channel this engine does not write native posts for gets {@see general()},
 * which is the conservative shape rather than a guess: one short post, no
 * chain, a square picture.
 */
final readonly class ChannelPlaybook
{
    /**
     * @param  list<string>  $angles  the shapes worth trying, in the order they are tried
     */
    private function __construct(
        public ChannelType $channel,
        public int $segmentLimit,
        public int $maxSegments,
        public int $idealChars,
        public int $hashtagCeiling,
        public int $questionBonus,
        public array $angles,
        public int $imageWidth,
        public int $imageHeight,
        private string $rules,
        /** @var array<string, string> */
        private array $shapes,
    ) {}

    public static function for(ChannelType $channel): self
    {
        return match ($channel) {
            ChannelType::Threads => self::threads(),
            ChannelType::X => self::x(),
            ChannelType::Instagram => self::instagram(),
            default => self::general($channel),
        };
    }

    /**
     * The non-negotiable rules for this channel, as prompt text.
     *
     * Written as instructions and not as a description, for the reason
     * {@see DraftCandidates} gives about §2's
     * list: a model shown a list of the shapes that fail will write one. Each
     * negative below is therefore phrased as the positive it replaces.
     */
    public function rules(): string
    {
        return $this->rules;
    }

    /**
     * The one thing that differs between the calls in a candidate pool.
     *
     * An angle the channel does not define falls back to its own first angle
     * rather than to a generic instruction, so a caller that passes a stale
     * angle string gets a usable candidate instead of a bland one.
     */
    public function shape(string $angle): string
    {
        return $this->shapes[$angle] ?? $this->shapes[$this->angles[0]];
    }

    /** Whether this channel writes a caption with a picture rather than a post. */
    public function isCaptionChannel(): bool
    {
        return $this->channel === ChannelType::Instagram;
    }

    /**
     * Threads: takes, not tips.
     *
     * The ceiling is §2's and the API's. The ideal is not: the best Threads
     * posts are well under half the limit, and a post that fills the box reads
     * as the тред-эссе §2 names among the shapes that do not work. Zero
     * hashtags — hashtag culture never took on Threads, and a hashtag on a
     * conversational post reads as marketing, which is the register that stops
     * the replies §1 measures the account on.
     */
    private static function threads(): self
    {
        return new self(
            channel: ChannelType::Threads,
            segmentLimit: (int) config('social.threads.text_limit', 500),
            maxSegments: (int) config('social.threads.max_segments', 3),
            idealChars: PostFormat::CAPTION_CHARS,
            hashtagCeiling: 0,
            // §2's own number, unchanged. This is the channel the constant was
            // derived from and the one measured on replies in the first hour.
            questionBonus: PostFormat::QUESTION,
            angles: ['question', 'take', 'contrarian', 'observation', 'caption'],
            imageWidth: (int) config('content_studio.images.threads.width', 1080),
            imageHeight: (int) config('content_studio.images.threads.height', 1080),
            rules: implode("\n", [
                'Threads rules, which are not negotiable:',
                '- Open on the point. No warm-up, no "here is a thought", no announcing what the post is about.',
                '- The whole post is the hook. Every line is doing work or it is cut.',
                '- Write in first or second person. Not "brands often struggle with X" but "we kept getting X wrong until Y".',
                '- A take outperforms a tip here. Say the thing you actually believe, not the balanced summary of it.',
                '- End on something a reader with an opinion would answer.',
                '- No hashtags at all.',
                '- Conviction and brevity. A short strong post beats a long careful one.',
            ]),
            shapes: [
                'question' => 'Write it as a real question to the reader — one somebody with an opinion '
                    .'would want to answer. Not rhetorical, and not one you then answer yourself.',
                'take' => 'Write it as an opinion you are willing to defend. State it in the first line, '
                    .'then give the one reason it is true. No hedging, no "it depends", no both-sides ending.',
                // The shape the platform rewards hardest and the one a brand
                // account reaches for least. Kept beside `take` rather than
                // folded into it: "what I think" and "what everybody has wrong"
                // are different posts, and only the second one argues.
                'contrarian' => 'Write it against the usual advice on this: name what everybody says, '
                    .'then say plainly why it is wrong here. No insults and no strawman — the thing you '
                    .'are arguing with has to be the thing people actually believe.',
                'observation' => 'Write it as one personal observation with something concrete in it: a '
                    .'number, a price, a date, something we actually saw. No general advice.',
                'caption' => 'Write it as a short caption for a photograph — two or three sentences at '
                    .'most, ending on something the reader can respond to.',
            ],
        );
    }

    /**
     * X: one point, standing entirely on its own.
     *
     * The limit is the format rather than a constraint, which is why the ideal
     * is most of it: a post with room left over on X is usually a post that
     * said its one thing. The chain ceiling is higher than Threads' because a
     * numbered thread is a native X shape — but only when the idea needs the
     * room, which is what the justification requirement enforces.
     */
    private static function x(): self
    {
        return new self(
            channel: ChannelType::X,
            segmentLimit: 280,
            maxSegments: 6,
            idealChars: 240,
            hashtagCeiling: 1,
            // Less than half of Threads'. A question works on X, but the native
            // shape is the flat argument, and paying Threads rates for a
            // question mark here had a measurable cost: an idea's Threads post
            // and its X post both came back as the same question, because the
            // bonus outweighed everything that made them different posts. See
            // ContentStudioAssistant::angles().
            questionBonus: 8,
            angles: ['take', 'observation', 'question', 'contrarian'],
            imageWidth: (int) config('content_studio.images.x.width', 1200),
            imageHeight: (int) config('content_studio.images.x.height', 675),
            rules: implode("\n", [
                'X rules, which are not negotiable:',
                '- One point per post. Not two, not one and a half.',
                '- It must stand alone. Someone who has never heard of us, reading only this, understands and can reply.',
                '- Open with the substance. No preamble, and never announce a thread.',
                '- Be specific. "Work smarter" content gets nothing here; a named number or a named thing does.',
                '- No hashtags unless it is a live tag we are genuinely part of, and then only one.',
                '- Never pad to fill the limit. A 140-character post is not worse than a 279-character one.',
            ]),
            shapes: [
                'take' => 'Write it as one argument stated flatly, in a single post. The first six words '
                    .'carry it. No wind-up.',
                'observation' => 'Write it as one specific thing we noticed while doing the work, with the '
                    .'number or the name in it.',
                'question' => 'Write it as a question that somebody in this field would have a real answer '
                    .'to and would want to give.',
                'contrarian' => 'Write it against the conventional advice on this subject: name what '
                    .'everybody says, then say why it is wrong here. No insults, no strawman.',
            ],
        );
    }

    /**
     * Instagram: the first line buys the tap.
     *
     * The limit is the caption ceiling the API enforces. `idealChars` is the
     * whole caption's target rather than a hook length — the hook is checked
     * separately in {@see ChannelPostScore::hooksBeforeTheFold()}, because a
     * caption can be the right length overall and still bury its first line.
     * Hashtags are the one place they still do something, and even here the
     * ceiling is a ceiling rather than a target.
     */
    private static function instagram(): self
    {
        return new self(
            channel: ChannelType::Instagram,
            segmentLimit: 2200,
            maxSegments: 1,
            idealChars: 1200,
            hashtagCeiling: 10,
            // Between the two. Comments are what the caption is asked for, and
            // a question is the reliable way to get them — but a caption also
            // earns saves, which no question mark helps with.
            questionBonus: 14,
            angles: ['story', 'framework', 'question', 'before_after'],
            imageWidth: (int) config('content_studio.images.instagram.width', 1080),
            imageHeight: (int) config('content_studio.images.instagram.height', 1350),
            rules: implode("\n", [
                'Instagram rules, which are not negotiable:',
                '- The first line is the hook and it is cut off at about 125 characters. Earn the "more" tap there.',
                '- Never open with the brand name, a greeting, a date, or "we are excited to share".',
                '- A line break between every thought. No dense paragraphs.',
                '- One clear thing to do at the end — save it, answer it, or come and ask.',
                '- No AI filler: no "in today\'s fast-paced world", no "let\'s dive in", no "game-changer".',
            ]),
            shapes: [
                'story' => 'Write it as hook, then the short story of what happened, then what it taught '
                    .'us, then one thing to do. Real specifics, not a parable.',
                'framework' => 'Write it as hook, then the three or four parts of how this actually works, '
                    .'each on its own line, then one thing to do.',
                'question' => 'Write it as a question in the first line, then the two ways people usually '
                    .'answer it, then ask which one the reader is.',
                'before_after' => 'Write it as what it was like before, what changed, and what it is like '
                    .'now — with the concrete difference named.',
            ],
        );
    }

    /**
     * A channel this engine has no native voice for yet.
     *
     * Deliberately the narrow shape: one post, short, no chain. A default that
     * guessed generously would let a channel nobody has written rules for ship
     * the longest thing the model produced.
     */
    private static function general(ChannelType $channel): self
    {
        return new self(
            channel: $channel,
            segmentLimit: 500,
            maxSegments: 1,
            idealChars: PostFormat::CAPTION_CHARS,
            hashtagCeiling: 0,
            questionBonus: PostFormat::QUESTION,
            angles: ['observation'],
            imageWidth: (int) config('content_studio.images.default.width', 1080),
            imageHeight: (int) config('content_studio.images.default.height', 1080),
            rules: 'Write one short post that says one thing. Open on the point and end on something a '
                .'reader could answer.',
            shapes: [
                'observation' => 'Write it as one concrete observation from the work, with something '
                    .'specific in it.',
            ],
        );
    }
}
