<?php

declare(strict_types=1);

namespace App\Publishing\Contracts;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\PublishToChannels;

/**
 * One transport (§9).
 *
 * §9 is a sentence about what does *not* change: "меняется транспорт, а не
 * механика". The deliveries table, the backoff ladder and replay stay common,
 * so this interface is deliberately not "a publisher" in the large sense — it
 * is the part of publishing that knows how to reach one kind of destination,
 * and nothing else. Channel selection is {@see PublishToChannels}, resolution
 * is {@see ChannelPublisherRegistry}, and the row every method here returns is
 * the same row for every transport.
 *
 * Every signature below is here because a caller asks for it, and the callers
 * are the six that phase 12.2a moved off `WebhookPublisher`:
 *
 * - {@see supports()} is what the registry indexes on, and what makes "which
 *   types can this engine publish to" answerable without a `match` somewhere
 *   that a second implementation would have to remember to extend.
 * - {@see queue()} takes a unit and one channel and hands back the delivery
 *   row. It takes no event: which event a destination is owed is a fact about
 *   what that destination has already received, so the transport works it out
 *   rather than being told. `ApprovalController`, `PublishApprovedCommand` and
 *   the repurpose tree all only ever knew "publish this unit here".
 * - {@see attempt()} is the one `DeliverWebhookJob` calls, once per attempt.
 *   It must never throw: an outcome is a row, and the job has `$tries = 1`
 *   precisely so the queue does not invent attempts the contract never
 *   promised.
 * - {@see replay()} is `DeliveryController::replay()` and `publish:replay`.
 *   Common mechanics, transport-specific bytes: a webhook replay re-sends the
 *   stored snapshot under a new delivery id, and a two-phase transport has to
 *   decide what part of a half-finished publication may be repeated.
 * - {@see ping()} is step three of the connection wizard, and {@see canPing()}
 *   is the question `ChannelController::ping()` used to ask as
 *   `$channel->type === ChannelType::Webhook`. "Can this channel be tested" is
 *   a property of its transport, not of its type spelling.
 * - {@see canAutopublish()} is the same replacement for the type check on
 *   `ChannelController::autopublish()`. A transport that cannot be left
 *   unattended says so once, here, instead of in every caller.
 *
 * What is *not* here is the whole point. No HMAC, no endpoint URL, no
 * `public_url` read out of a JSON response body: those are three facts about
 * the webhook contract, and a first-party API implementation would have to
 * lie about all three to satisfy an interface that mentioned them.
 *
 * `WebhookDelivery` is the return type throughout despite the name. It is the
 * shared deliveries table of §9 — the model is named after the phase that
 * created it, not after the only transport allowed to write to it.
 */
interface ChannelPublisher
{
    /** True when this transport is the one that speaks to channels of `$type`. */
    public function supports(ChannelType $type): bool;

    /**
     * Queue one unit for one channel, and hand back the row that records it.
     *
     * Idempotent per revision: asking twice for content that has not changed
     * must produce one delivery, because the operator pressing publish again
     * is asking "did that go out", not "send it twice".
     */
    public function queue(ContentItem $unit, Channel $channel): WebhookDelivery;

    /**
     * One attempt at one delivery. Never throws — see the class docblock.
     */
    public function attempt(WebhookDelivery $delivery): WebhookDelivery;

    /**
     * Send a settled delivery's stored content again, as a new delivery.
     */
    public function replay(WebhookDelivery $delivery): WebhookDelivery;

    /** True when a channel of this transport can be tested before it is used. */
    public function canPing(): bool;

    /**
     * Prove the connection, with no content unit involved: a channel is
     * normally connected before the project has written a word.
     */
    public function ping(Channel $channel, Project $project): WebhookDelivery;

    /** True when a channel of this transport may publish without a person watching. */
    public function canAutopublish(): bool;
}
