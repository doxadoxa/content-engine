<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\ContentIdea;
use App\Support\Social\ContentMix;

/**
 * What a post *is*, as opposed to what it is about.
 *
 * A {@see ContentIdea} already carried a pillar, a thesis and an angle, and all
 * three answer the same question: the subject. None of them answers the other
 * one — whether this is an opinion, a lesson, a result, a look behind the
 * curtain or an offer — and the consequence of not asking it was a month of
 * twenty ideas that were all, without exception, tips. "Four signs you may be
 * planning maintenance". "Upholstery care starts with knowing when". "With
 * marble, restraint is part of good care". Every one a how-to, on a set of
 * channels where the platform writers agree that a take outperforms a tip.
 *
 * Three things follow from the kind and none of them followed from the subject:
 *
 *   - **where the post belongs** ({@see channels()}). An opinion has nothing to
 *     photograph and no reason to be on Instagram; a result has nothing to
 *     argue and every reason to be. Before this, every idea went to all three
 *     channels — which is the cross-posting the whole engine argues against,
 *     arranged at the planning step so that no amount of care in the drafting
 *     step could undo it.
 *   - **what a month should contain** ({@see ContentMix}). A mix is a property
 *     of the set, so it needs a name for the members.
 *   - **whether Instagram gets a carousel** ({@see instagramFormat()}). That
 *     used to be `$this->scheduled_for->day % 2` — a carousel on even days.
 *     The format of the post was decided by the parity of the date.
 *
 * Five and not fifteen. Each one has to be a shape a writer can actually hold
 * in their head and a reader can tell apart in a feed; a taxonomy finer than
 * that produces categories the model assigns at random.
 */
enum PostKind: string
{
    /** An opinion the brand is willing to defend. The shape Threads rewards most. */
    case Take = 'take';

    /** Teaching: how something is actually done. */
    case HowTo = 'how_to';

    /** A result, a case, a before and after. What makes the teaching credible. */
    case Proof = 'proof';

    /** The work itself — process, standards, the parts customers never see. */
    case Behind = 'behind';

    /**
     * Somebody at home, in a home that has been looked after.
     *
     * The one kind whose subject is a person rather than the work, and the one
     * the other five could not be talked into being. Reviewing the first month
     * of pictures, the complaint was that the set is cold — no people, no soul
     * — and the cause was structural rather than a matter of wording: a `take`
     * argues, a `how_to` instructs, a `proof` demonstrates, a `behind` shows
     * the unglamorous part and an `offer` sells. Every one of them is about the
     * service. A reader is not buying a service, they are buying an evening
     * back, and nothing in the calendar was allowed to say so.
     *
     * Kept honest by what it may not do: it is not a tip with a person added,
     * and it does not end by pointing at the company. If it argues, it is a
     * `take`; if it shows a result, it is a `proof`. See {@see brief()}.
     */
    case Life = 'life';

    /** A direct offer. Capped rather than targeted; see {@see ContentMix}. */
    case Offer = 'offer';

    public function label(): string
    {
        return match ($this) {
            self::Take => 'Opinion',
            self::HowTo => 'How-to',
            self::Proof => 'Proof',
            self::Behind => 'Behind the scenes',
            self::Life => 'Life at home',
            self::Offer => 'Offer',
        };
    }

    /**
     * The channels this kind is native to.
     *
     * Two at most, and that ceiling is the point rather than a coincidence of
     * the lists below. An idea that goes everywhere is an idea that was written
     * once and trimmed twice, and a reader who follows a brand on two platforms
     * sees exactly that. Which two differs by kind:
     *
     *   - **Take** — Threads and X, the two places an argument stands on its
     *     own. Not Instagram, where a post with nothing to look at is a wall of
     *     text under a stock photograph.
     *   - **HowTo** — Instagram, where a carousel is the native teaching shape
     *     and the format people save, and X, where a compact procedure is a
     *     useful post. Not Threads, which rewards the opinion about the method
     *     rather than the method.
     *   - **Proof** — Instagram, because a result is a thing you show, and
     *     Threads, because "here is what actually happened" is a conversation.
     *   - **Behind** — the same two, for the same reason: it is visual, and it
     *     invites a reply.
     *   - **Life** — Instagram, where a moment in a home is the whole post,
     *     and Threads, where it is the one kind that reliably gets answered:
     *     people reply to a room they recognise. Not X, which rewards the
     *     compact argument and treats a warm observation as filler.
     *   - **Offer** — Instagram alone. An offer on Threads or X with no
     *     conversational frame is «самопродвижение без разговорной рамки», the
     *     one shape §2 names with no working version.
     *
     * @return list<ChannelType>
     */
    public function channels(): array
    {
        return match ($this) {
            self::Take => [ChannelType::Threads, ChannelType::X],
            self::HowTo => [ChannelType::Instagram, ChannelType::X],
            self::Proof => [ChannelType::Instagram, ChannelType::Threads],
            self::Behind => [ChannelType::Instagram, ChannelType::Threads],
            self::Life => [ChannelType::Instagram, ChannelType::Threads],
            self::Offer => [ChannelType::Instagram],
        };
    }

    /**
     * Whether Instagram gets a carousel or one picture.
     *
     * Decided by what the post is, which is the whole of the fix: a carousel is
     * a sequence, and only teaching is reliably a sequence. A result is one
     * image with a caption; an offer is one image; a look behind the work is
     * one image. The previous rule — even day, carousel — meant an idea moved
     * by one day in a refinement silently changed format.
     */
    public function instagramFormat(): string
    {
        return $this === self::HowTo ? 'carousel' : 'image';
    }

    /**
     * What writing this kind means, as an instruction to the drafting model.
     *
     * Phrased as the thing to do rather than the category it belongs to. A
     * model told "this is a `proof` post" writes a how-to and labels it proof;
     * a model told to name what changed and what it cost writes the post.
     */
    public function brief(): string
    {
        return match ($this) {
            self::Take => 'This is an opinion post. Say what you actually believe about this and be '
                .'willing to be disagreed with. No balanced summary, no "it depends", no ending that '
                .'takes it back. One position, one reason.',
            self::HowTo => 'This is a teaching post. Show how the thing is actually done, in the order '
                .'somebody would do it. Concrete steps over principles.',
            self::Proof => 'This is a proof post. Name what the situation was, what changed, and how you '
                .'know — the specific before and after. Use only the evidence given; if there is no '
                .'evidence for a number, do not produce one.',
            self::Behind => 'This is a behind-the-scenes post. Show the part of the work customers never '
                .'see: the standard, the step everybody skips, what goes wrong and what it takes to get '
                .'right. It should read like somebody who does this describing it, not like marketing.',
            self::Life => 'This is a post about somebody at home, and the home is one that has been '
                .'looked after. Write one small true moment — a room at a particular hour, a thing '
                .'somebody can do again because the space is ready for it. The cleaning is why the '
                .'moment is possible and is not the subject of the post: mention it once at most, or '
                .'not at all. This is the one kind that may not teach. No steps, no tips, no checklist, '
                .'no "here is how", and no ending that turns back to the company. If you find yourself '
                .'advising, you are writing the wrong kind.',
            self::Offer => 'This is an offer post, and it is the one post of the month that is allowed to '
                .'be one. Say plainly what is on offer, who it is for and what happens next. No hype, no '
                .'urgency that is not real.',
        };
    }

    /**
     * The shapes worth trying for this kind on this channel.
     *
     * Narrows the channel's own list rather than replacing it: the playbook
     * knows what works on the platform, this knows what this post is, and the
     * pool wants the intersection. An empty intersection falls back to the
     * channel's list — a caller that adds a channel and forgets this table gets
     * a slightly less pointed pool, not an empty one.
     *
     * @return list<string>
     */
    public function anglesOn(ChannelType $channel): array
    {
        return match ($this) {
            self::Take => ['take', 'contrarian', 'question'],
            self::HowTo => $channel === ChannelType::Instagram
                ? ['framework', 'story', 'before_after']
                : ['observation', 'framework', 'take'],
            self::Proof => $channel === ChannelType::Instagram
                ? ['before_after', 'story', 'framework']
                : ['observation', 'caption', 'question'],
            self::Behind => $channel === ChannelType::Instagram
                ? ['story', 'before_after', 'question']
                : ['observation', 'caption', 'take'],
            self::Life => $channel === ChannelType::Instagram
                ? ['story', 'question']
                : ['observation', 'caption', 'question'],
            self::Offer => ['story', 'framework', 'caption', 'observation'],
        };
    }

    /**
     * What the picture has to show for this kind of post.
     *
     * The last of the four things the kind decides, and the one that was
     * missing longest. Channels, format and angles were all derived from it
     * while the image brief was left to the writing model with no instruction
     * beyond "be specific" — and what came back was competent and inert: a hand
     * setting down a mug, a hand wiping a sink, a hand loading a bowl. Three
     * posts, one beat, nothing in any frame worth stopping for.
     *
     * That was the house style doing exactly as told. "Documentary, unstyled,
     * mid-gesture" removes the stock gloss and puts nothing in its place, and
     * an image with nothing in it is a worse post than a glossy one — on a feed
     * the picture is the whole of the first impression.
     *
     * So each kind names the thing in the frame that earns the look. They are
     * not styles; they are subjects, because that is the decision the writing
     * model was getting wrong.
     */
    public function shot(): string
    {
        return match ($this) {
            self::Take => 'The picture has to carry the same argument as the words: show the contrast the '
                .'post is about, or the exact detail somebody would disagree about. Not an illustration '
                .'of the topic in general.',
            self::HowTo => 'Show the step itself, close enough to read: the tool in contact with the '
                .'surface, the thing actually happening. If a viewer cannot tell what is being done, the '
                .'picture has failed.',
            self::Proof => 'Show the difference. The state before or the state after, or both in one '
                .'frame across a boundary — a cleaned half against an uncleaned half, an edge where the '
                .'work stops. The change is the subject.',
            self::Behind => 'Show the part nobody photographs: the dirt itself, what came off, the '
                .'awkward corner, the tool worn from use. Unglamorous and specific.',
            self::Life => 'Show a person in the room, using it. Somebody occupied with something '
                .'ordinary — eating, reading, getting out of the door, a child at a table — in a space '
                .'that is plainly cared for. Nobody cleaning, no cloth, no gloves, no product: this is '
                .'the after, hours later, when the work is invisible and the point of it is not.',
            self::Offer => 'Show the outcome somebody is buying — the finished state, at the scale a '
                .'person would notice it.',
        };
    }

    /** The value an unreadable or absent kind falls back to. */
    public static function fallback(): self
    {
        return self::HowTo;
    }

    /**
     * A kind as a model actually spells it.
     *
     * Hyphens and spaces are folded to underscores because "how-to" and "how
     * to" are what a model writes when the instruction says `how_to`, and the
     * cost of not folding them is invisible: an unreadable value falls back to
     * {@see fallback()}, which is `HowTo` — the exact kind this whole feature
     * exists to stop over-producing — and then rewrites the idea's channels and
     * its Instagram format to match. A month made monotonous by a spelling is
     * indistinguishable from one made monotonous on purpose.
     */
    public static function tryFromLoose(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(str_replace([' ', '-'], '_', trim(strtolower($value))));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
