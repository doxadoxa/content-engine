<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\ChannelType;
use App\Enums\SocialBand;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\WebhookDelivery;
use App\Social\Governor;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Where a unit goes, as opposed to how it gets there (§9).
 *
 * These three rules were methods on `WebhookPublisher`, each ending in
 * `where('type', ChannelType::Webhook)`, and that line was the bug: choosing
 * the audience for a publication is not part of speaking HTTP to a receiver.
 * It survived as long as it did because with one transport the two questions
 * have the same answer.
 *
 * The rules themselves are unchanged, only generalised from "webhook" to
 * "every type something can deliver to":
 *
 * - {@see publish()} — enabled channels. The blunt one: send it wherever it
 *   can go.
 * - {@see publishAutomatically()} — enabled, `autopublish`, verified, and a
 *   unit of a band §5 lets travel unattended at all. Nothing leaves unattended
 *   through a connection that has never answered a ping, and two of the six
 *   social bands never leave unattended through any connection.
 * - {@see publishManually()} — enabled and verified. A person is watching, so
 *   the `autopublish` toggle is not their answer to give twice.
 *
 * Across all three, and before any of them, the governor's ceiling: see
 * {@see refusal()}. Three rules about *where* a unit goes are three rules that
 * all needed the same answer to "may it go at all", and every path that reaches
 * a transport reaches it through {@see deliver()}.
 */
class PublishToChannels
{
    public function __construct(
        private readonly ChannelPublisherRegistry $publishers,
        private readonly Governor $governor,
    ) {}

    /**
     * Why this unit is not going out right now, or null if it is.
     *
     * Public because the refusal is only half useful as a `[]` return: §7 makes
     * the explanation mandatory — "чего движок делать не стал и почему" — and a
     * screen that can only report "nothing was queued" turns an enforced
     * ceiling back into a silent one. The controller and the command each print
     * this sentence; {@see deliver()} enforces it whether they ask or not.
     */
    public function refusal(ContentItem $unit, ?CarbonInterface $at = null): ?string
    {
        return $this->governor->refusalToSend($unit, $at);
    }

    /**
     * Every enabled channel that can take this unit.
     *
     * @return list<WebhookDelivery>
     */
    public function publish(ContentItem $unit): array
    {
        return $this->deliver($unit, $this->enabled()->get());
    }

    /**
     * Enabled, opted in, and proven — the unattended path.
     *
     * And, before any of that, a unit whose band is allowed to travel it. §5
     * marks Conversation and Reaction «автопаблиш: никогда», and
     * {@see SocialBand::canEverAutopublish()} says why the word is "never"
     * rather than "not yet": a reply to a living person sent as the brand with
     * nobody watching, and a comment on the news, which is the one place where
     * being wrong is both fast and public.
     *
     * The refusal belongs here rather than only in the caller because "never"
     * has to be true of the path, not of the one route that currently reaches
     * it. Nothing can arrive here today only because `scopeRoots()` excludes
     * social posts wholesale — a shield 12.5 removes. Written now, the day it
     * is removed is a day nothing changes.
     *
     * Asked of the band and not of the channel: an unattended send is a
     * property of what is being sent, and a social channel is perfectly
     * entitled to autopublish the four bands that may use it.
     *
     * @return list<WebhookDelivery>
     */
    public function publishAutomatically(ContentItem $unit): array
    {
        if ($unit->social_band?->canEverAutopublish() === false) {
            return [];
        }

        return $this->deliver($unit, $this->enabled()
            ->where('autopublish', true)
            ->whereNotNull('verified_at')
            ->get());
    }

    /**
     * Enabled and proven — a person pressed publish.
     *
     * @return list<WebhookDelivery>
     */
    public function publishManually(ContentItem $unit): array
    {
        return $this->deliver($unit, $this->enabled()
            ->whereNotNull('verified_at')
            ->get());
    }

    /**
     * How many channels {@see publishManually()} would reach — the number the
     * unit card prints beside its publish button.
     *
     * Here rather than in the controller because it was a fourth copy of the
     * same `where('type', ChannelType::Webhook)`, and a count that disagrees
     * with what the button then does is worse than no count: the panel offered
     * "publish to 3 channels" for units that would reach none of them.
     */
    public function manualTargets(ContentItem $unit): int
    {
        return $this->enabled()
            ->whereNotNull('verified_at')
            ->get()
            ->filter(fn (Channel $channel): bool => $this->accepts($channel, $unit))
            ->count();
    }

    /**
     * Channels of a type something can actually deliver to.
     *
     * The `whereIn` is the hardcoded webhook filter's replacement, and it reads
     * the answer off the registry rather than restating it. A type with no
     * transport — WordPress, the pull API — is not a delivery that fails, it is
     * a destination that was never selected.
     *
     * @return Builder<Channel>
     */
    private function enabled(): Builder
    {
        return Channel::query()
            ->where('is_enabled', true)
            ->whereIn('type', array_map(
                static fn (ChannelType $type): string => $type->value,
                $this->publishers->publishableTypes(),
            ));
    }

    /**
     * Grouped by type, one transport per group.
     *
     * The unit is *not* marked published here, and the comment that used to
     * live on this loop is worth keeping: queueing a delivery is a promise to
     * try, and treating it as proof left the panel reading "Published" beside
     * an article no reader could reach. The transition happens when a receiver
     * confirms.
     *
     * The ceiling is asked here and not in each of the three public methods for
     * the same reason the channel filter was moved into this class: a rule with
     * three copies is three rules. Every route that publishes anything — the
     * approvals screen, `publish:approved`, the tick — funnels through this
     * method, so this is the line §10's "архитектура, а не дисциплина
     * оператора" is actually spelled on.
     *
     * @param  Collection<int, Channel>  $channels
     * @return list<WebhookDelivery>
     */
    private function deliver(ContentItem $unit, Collection $channels): array
    {
        if ($this->refusal($unit) !== null) {
            return [];
        }

        $deliveries = [];

        /** @var Collection<int, Channel> $group */
        foreach ($channels->groupBy(static fn (Channel $channel): string => $channel->type->value) as $type => $group) {
            $publisher = $this->publishers->for(ChannelType::from((string) $type));

            foreach ($group as $channel) {
                if (! $this->accepts($channel, $unit)) {
                    continue;
                }

                $deliveries[] = $publisher->queue($unit, $channel);
            }
        }

        return $deliveries;
    }

    /**
     * A post is not an article, and a receiver has no way to tell.
     *
     * §9 replaces the "social over webhook" arrangement of §5 with real
     * transports, which means a social unit reaching a webhook channel is no
     * longer an odd payload — it is a 300-character post arriving at a static
     * site generator as an article, with an article's schema.org type, a slug,
     * and a page of its own. The guard is written now, while webhook is the
     * only transport, so that it is already true on the day the second one
     * lands rather than being the thing that day discovers.
     *
     * Symmetric deliberately: an article does not belong on a social channel
     * either. §1's third fact is that a cross-post reads as a cross-post, and
     * §8.1's repurpose tree exists precisely so that what goes to a social
     * channel is a post written to be one.
     */
    private function accepts(Channel $channel, ContentItem $unit): bool
    {
        return $unit->isSocial() === $channel->type->isSocial();
    }
}
