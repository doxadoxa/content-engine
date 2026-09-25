<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\ChannelType;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\WebhookDelivery;
use App\Publishing\Articles\ArticleSchedules;
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
 * - {@see publishAutomatically()} — the article's publication schedule, which
 *   decides when an approved article goes out unattended.
 * - {@see publishManually()} — enabled and verified. A person is watching, so
 *   the `autopublish` toggle is not their answer to give twice.
 *
 * Across all three, and before any of them, the schedule's hold: see
 * {@see refusal()}. Three rules about *where* a unit goes are three rules that
 * all needed the same answer to "may it go at all", and every path that reaches
 * a transport reaches it through {@see deliver()}.
 */
class PublishToChannels
{
    public function __construct(
        private readonly ChannelPublisherRegistry $publishers,
    ) {}

    /**
     * Why this unit is not going out right now, or null if it is.
     *
     * Public because the refusal is only half useful as a `[]` return: §7 makes
     * the explanation mandatory — "чего движок делать не стал и почему" — and a
     * screen that can only report "nothing was queued" turns an enforced
     * hold back into a silent one. The controller and the command each print
     * this sentence; {@see deliver()} enforces it whether they ask or not.
     */
    public function refusal(ContentItem $unit): ?string
    {
        $schedule = ArticleSchedule::query()->where('content_item_id', $unit->id)->first();
        if ($schedule !== null && $schedule->status !== 'completed'
            && (! in_array($schedule->status, ['active', 'blocked'], true) || $schedule->publish_at->isFuture())) {
            return 'This article waits for its publication schedule.';
        }

        return null;
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
     * The unattended path, which for an article is its publication schedule:
     * {@see ArticleSchedules::dispatch()} decides whether it goes now, later,
     * or not at all.
     *
     * @return list<WebhookDelivery>
     */
    public function publishAutomatically(ContentItem $unit): array
    {
        return app(ArticleSchedules::class)->dispatch($unit);
    }

    /**
     * Enabled and proven — a person pressed publish.
     *
     * @return list<WebhookDelivery>
     */
    public function publishManually(ContentItem $unit): array
    {
        if (ArticleSchedule::query()->where('content_item_id', $unit->id)->where('status', '!=', 'completed')->exists()) {
            return app(ArticleSchedules::class)->dispatch($unit);
        }

        return $this->deliver($unit, $this->enabled()
            ->whereNotNull('verified_at')
            ->get());
    }

    /** Queue exactly the selected website; the schedule transaction links its durable identity before dispatch. */
    public function publishToSelected(ContentItem $unit, Channel $channel): ?WebhookDelivery
    {
        if ($unit->project_id !== $channel->project_id || ! app(ArticleSchedules::class)->compatible($channel)) {
            return null;
        }

        return $this->deliver($unit, new Collection([$channel]))[0] ?? null;
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
     * The hold is asked here and not in each of the three public methods for
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
                $deliveries[] = $publisher->queue($unit, $channel);
            }
        }

        return $deliveries;
    }
}
