<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Billing\StripeWebhook;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Cashier's event, our projection.
 *
 * Cashier keeps its own tables in step with Stripe and stops there; nothing it
 * writes tells the engine whether a project may spend. This is the join between
 * the two — see {@see StripeWebhook} for why it listens to `WebhookReceived`
 * rather than `WebhookHandled`.
 *
 * Synchronous, not queued. A subscription that has been paid for should be
 * usable by the time the customer is redirected back from Checkout, and a queue
 * puts an unbounded delay between the payment and the engine starting. The work
 * is a handful of indexed writes.
 */
class ProjectStripeWebhook
{
    public function __construct(private readonly StripeWebhook $webhook) {}

    public function handle(WebhookReceived $event): void
    {
        $this->webhook->handle($event->payload);
    }
}
