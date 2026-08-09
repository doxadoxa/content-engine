<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a project publishes.
 *
 * Phase 2 only records the configuration; the adapters that act on it arrive in
 * phase 6 (webhook) and phase 8 (the rest). The cases are all listed now
 * because the enum is what the `type` column is validated against, and adding a
 * case later is a migration on every row that already stored a string.
 */
enum ChannelType: string
{
    /** The engine POSTs to a receiver the project owns. The phase 6 contract. */
    case Webhook = 'webhook';

    case WordPress = 'wordpress';

    case LinkedIn = 'linkedin';

    case X = 'x';

    case Instagram = 'instagram';

    case Telegram = 'telegram';

    /**
     * The first social channel the engine actually speaks to (§9).
     *
     * Unlike the other social cases it does not go through the webhook relay.
     * Threads has a first-party API, and routing it through a receiver the
     * project owns would put an OAuth token for Meta on four foreign
     * installations instead of one. So it gets its own publisher, and the
     * two-phase container/publish dance that comes with it.
     */
    case Threads = 'threads';

    /** The project pulls finished units from the engine instead of being pushed to. */
    case PullApi = 'pull_api';

    /**
     * The types an operator may pick from in this deployment.
     *
     * Not the same question as {@see cases()}, which is what the `type` column
     * is validated against and has to keep listing everything any row has ever
     * stored. This is the shorter list of what may be chosen *now*, and the one
     * thing that shortens it today is `social.enabled`: an installation with no
     * Meta app cannot reach Threads, so offering it in the connect dialog sells
     * a channel that would sit at "not connected" forever and never appear in
     * `ChannelPublisherRegistry::publishableTypes()`.
     *
     * A static method rather than a filter in the controller because the
     * channel list and `ChannelRequest` both need the answer, and a form that
     * offers a choice its own validator rejects is the sort of disagreement
     * that only shows up when somebody tries it.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => $type !== self::Threads || (bool) config('social.enabled'),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Webhook => 'Webhook',
            self::WordPress => 'WordPress',
            self::LinkedIn => 'LinkedIn',
            self::X => 'X',
            self::Instagram => 'Instagram',
            self::Telegram => 'Telegram',
            self::Threads => 'Threads',
            self::PullApi => 'Pull API',
        };
    }

    /**
     * True when the channel takes short derivative posts rather than articles.
     * Read by the repurpose tree in phase 8; here it is what tells the channel
     * list which targets an article can actually go to.
     */
    public function isSocial(): bool
    {
        return match ($this) {
            self::LinkedIn, self::X, self::Instagram, self::Telegram, self::Threads => true,
            self::Webhook, self::WordPress, self::PullApi => false,
        };
    }

    /**
     * True when an article may be cut up and fanned out to this channel.
     *
     * Not a synonym for {@see isSocial()}, and this is the distinction a future
     * reader is most likely to collapse back. "Social" says what the channel
     * is; this says where the repurpose tree of §8.1 is allowed to deliver.
     *
     * Threads is social and takes no cross-posts. §1's third fact is explicit
     * that cross-posting fails on tone mismatch — "статья, порезанная на посты,
     * читается как статья, порезанная на посты" — and a weak post there is not
     * a zero but a minus, because the algorithm reads the account's history and
     * narrows the display window of the *next* post. Feeding it slices of an
     * article is the §10 "аккаунт под автоматикой" risk arriving through the
     * back door.
     *
     * Threads does take derivatives: §5 budgets the Derivative band at ≤1 a
     * week. They come through `social_plan` in 12.5, which applies the Threads
     * format, the band and the governor — not through the article's repurpose
     * tree, which applies none of the three.
     */
    public function takesArticleDerivatives(): bool
    {
        return match ($this) {
            self::LinkedIn, self::X, self::Telegram => true,
            self::Instagram, self::Threads, self::Webhook, self::WordPress, self::PullApi => false,
        };
    }
}
