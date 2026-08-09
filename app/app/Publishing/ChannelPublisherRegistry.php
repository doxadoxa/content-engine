<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\ChannelType;
use App\Models\WebhookDelivery;
use App\Publishing\Contracts\ChannelPublisher;
use App\Publishing\Exceptions\MismatchedChannelPublisher;
use App\Publishing\Exceptions\UnknownChannelPublisher;
use Illuminate\Contracts\Container\Container;

/**
 * Which transport speaks to which channel (§9).
 *
 * The registry exists because `where('type', ChannelType::Webhook)` was written
 * in five places and each one was a decision nobody could see: three channel
 * selections in the publisher and two `abort_unless` type checks in the
 * controller. Collected here they are one decision, and adding a second
 * transport is a line in `AppServiceProvider` rather than a search for the
 * places that assumed there was only ever one.
 *
 * Publishers are held as class names and resolved through the container on
 * demand. That is not laziness for its own sake: a transport is free to depend
 * on things — an HTTP client, an integration's token store — that have no
 * business being constructed when all anybody asked was "is this type
 * publishable at all", which is the question the channel list asks on every
 * page load.
 */
class ChannelPublisherRegistry
{
    /** @var array<string, class-string<ChannelPublisher>> */
    private array $publishers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Claim a type for a transport.
     *
     * The pair is checked against the transport's own {@see
     * ChannelPublisher::supports()} — a registration that disagrees with itself
     * is {@see MismatchedChannelPublisher} — but the check happens at
     * {@see for()} rather than here, and that is not a preference. Two things
     * make it impossible on this line:
     *
     * - the whole point of holding class names is that asking "is this type
     *   publishable at all" must not construct anything, and that is the
     *   question the channel list asks on every page load;
     * - `WebhookPublisher` depends on `PublishToChannels`, which depends on
     *   this registry. Resolving a publisher from inside the closure that
     *   builds the registry singleton would re-enter that closure before the
     *   container has an instance to hand back, and recurse.
     *
     * So the assertion sits where the transport is being constructed anyway. It
     * costs nothing, and a mismatched pair cannot reach a delivery: `for()` is
     * the only way out of this class.
     *
     * @param  class-string<ChannelPublisher>  $publisher
     */
    public function register(ChannelType $type, string $publisher): self
    {
        $this->publishers[$type->value] = $publisher;

        return $this;
    }

    /**
     * The transport for a type, or an exception naming the type.
     *
     * Never null. See {@see UnknownChannelPublisher} for why a missing
     * transport has to be an error rather than a quiet no-op, and
     * {@see register()} for why the pair is verified here.
     */
    public function for(ChannelType $type): ChannelPublisher
    {
        $publisher = $this->publishers[$type->value] ?? null;

        if ($publisher === null) {
            throw UnknownChannelPublisher::for($type);
        }

        $instance = $this->container->make($publisher);

        if (! $instance->supports($type)) {
            throw MismatchedChannelPublisher::for($type, $publisher);
        }

        return $instance;
    }

    /**
     * The transport for a delivery, found through the channel it is addressed
     * to — which is how `DeliverWebhookJob` picks one up without the dispatcher
     * having had to record what kind of thing it was queueing.
     */
    public function forDelivery(WebhookDelivery $delivery): ChannelPublisher
    {
        return $this->for($delivery->loadMissing('channel')->channel->type);
    }

    public function publishes(ChannelType $type): bool
    {
        return isset($this->publishers[$type->value]);
    }

    /**
     * Every type that can be delivered to, which is what channel selection
     * filters on in place of the hardcoded `webhook`.
     *
     * @return list<ChannelType>
     */
    public function publishableTypes(): array
    {
        return array_map(
            static fn (string $type): ChannelType => ChannelType::from($type),
            array_keys($this->publishers),
        );
    }

    /**
     * "Can this channel be tested before anyone relies on it?"
     *
     * Two things have to be true: something knows how to reach the type at all,
     * and that transport has a way of proving a connection. `ChannelController`
     * asks this instead of comparing the type to `Webhook`, because a channel
     * with no adapter and a channel whose adapter cannot be dry-run are the
     * same 409 to an operator.
     */
    public function canPing(ChannelType $type): bool
    {
        return $this->publishes($type) && $this->for($type)->canPing();
    }

    /** The same question for the automatic-publishing toggle. */
    public function canAutopublish(ChannelType $type): bool
    {
        return $this->publishes($type) && $this->for($type)->canAutopublish();
    }
}
