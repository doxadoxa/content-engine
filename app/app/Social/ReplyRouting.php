<?php

declare(strict_types=1);

namespace App\Social;

use App\Enums\ReplyRoute;
use App\Models\ContentItem;
use App\Models\Interaction;

/**
 * The only reader of `social.threads.reply_to_foreign` (§11.1).
 *
 * The config comment says what the flag records — a fact about the platform we
 * have not been able to check, not a preference — and this class is where that
 * fact turns into behaviour. Two questions, in this order, and the order is the
 * point:
 *
 * 1. **Is this conversation on a post of ours?** If it is, the flag is
 *    irrelevant. Meta's reply-management documentation covers exactly this
 *    case: an incoming reply on our own thread, answered with `reply_to_id`.
 *    Nothing about it is in doubt, so nothing about it waits on §11.1.
 * 2. **Otherwise the flag decides.** Off — the documented default — the engine
 *    drafts and a person posts it. On, the engine may use `reply_to_id` against
 *    somebody else's post, which is the undocumented case §11.1 is about.
 *
 * **How "a post of ours" is decided, and why it errs the way it does.** The
 * evidence is the delivery journal §9 already keeps: `ThreadsPublisher` writes
 * `published_root_id` into `content_items.channel_payload` after every publish,
 * so a root id we can find there is a root we put there. A reply to a reply
 * still carries our root, so a deep thread resolves as ours. What does *not*
 * resolve is a reply to a post published outside this engine — by hand, or
 * before it was connected — and that falls to the operator, which is the safe
 * direction to be wrong in: the cost of it is one copy-paste, where the other
 * direction is an API call against the open question.
 *
 * ⚠️ Unverified against a live account, like everything else Threads-shaped
 * here. What is unverified is only the {@see ReplyRoute::Api} branch for a
 * foreign post — the branch the flag turns on — and it stays off until someone
 * runs it against a real token.
 */
final class ReplyRouting
{
    /** How this conversation may be answered, right now. */
    public function for(Interaction $interaction): ReplyRoute
    {
        if ($this->isOnOurOwnPost($interaction)) {
            return ReplyRoute::Api;
        }

        return $this->foreignRepliesAllowed() ? ReplyRoute::Api : ReplyRoute::ByHand;
    }

    /**
     * The same answer for a whole page of the duty queue, in one query.
     *
     * The screen shows twenty-five conversations and each one's answer depends
     * on whether we published its thread — twenty-five `exists()` calls, on the
     * screen §7 wants to open in seconds on a phone. One `whereIn` instead.
     *
     * @param  iterable<Interaction>  $interactions
     * @return array<string, ReplyRoute> keyed by interaction id
     */
    public function forMany(iterable $interactions): array
    {
        $candidates = [];
        $rows = [];

        foreach ($interactions as $interaction) {
            $rows[] = $interaction;

            foreach ($this->candidatesFor($interaction) as $candidate) {
                $candidates[$candidate] = true;
            }
        }

        $ours = $candidates === []
            ? []
            : ContentItem::query()
                ->whereIn('channel_payload->published_root_id', array_keys($candidates))
                // The whole journal rather than the json path on its own:
                // `pluck()` with a `->` selector builds the right SQL and then
                // reads the answer back under a column name Postgres never
                // returns, so the value arrives as a missing property. The cast
                // on the model gives the same string with one array lookup.
                ->pluck('channel_payload')
                ->map(static fn (mixed $journal): mixed => is_array($journal)
                    ? ($journal['published_root_id'] ?? null)
                    : null)
                // A closure rather than `is_string(...)`: Collection::filter
                // hands the callback a key as well as a value, and a
                // first-class callable of arity one refuses the second
                // argument under strict types.
                ->filter(static fn (mixed $root): bool => is_string($root))
                ->flip()
                ->all();

        $allowed = $this->foreignRepliesAllowed();
        $routes = [];

        foreach ($rows as $interaction) {
            $own = false;

            foreach ($this->candidatesFor($interaction) as $candidate) {
                if (array_key_exists($candidate, $ours)) {
                    $own = true;

                    break;
                }
            }

            $routes[(string) $interaction->getKey()] = $own || $allowed
                ? ReplyRoute::Api
                : ReplyRoute::ByHand;
        }

        return $routes;
    }

    /** §11.1's flag, read in exactly one place. */
    public function foreignRepliesAllowed(): bool
    {
        return (bool) config('social.threads.reply_to_foreign', false);
    }

    /**
     * Whether the thread this conversation is in was started by us.
     *
     * The parent is checked as well as the root because a conversation can
     * arrive with only one of them: the root is what groups a thread, the
     * parent is what we would answer, and a webhook payload missing `root_post`
     * is a payload shape rather than a foreign post.
     */
    public function isOnOurOwnPost(Interaction $interaction): bool
    {
        $candidates = $this->candidatesFor($interaction);

        if ($candidates === []) {
            return false;
        }

        return ContentItem::query()
            ->whereIn('channel_payload->published_root_id', $candidates)
            ->exists();
    }

    /**
     * The platform ids that would identify the thread as ours.
     *
     * @return list<string>
     */
    private function candidatesFor(Interaction $interaction): array
    {
        return array_values(array_filter([
            $interaction->root_external_id,
            $interaction->parent_external_id,
        ]));
    }
}
