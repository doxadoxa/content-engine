<?php

declare(strict_types=1);

namespace App\Publishing\Jobs;

use App\Models\WebhookDelivery;
use App\Publishing\ChannelPublisherRegistry;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One attempt at one delivery, through whichever transport the delivery is
 * addressed to (§9).
 *
 * `$tries = 1`, for the same reason the pipeline's step job has it: the retry
 * ladder is published in the contract and walked by the publisher, and a queue
 * retrying underneath it would produce attempts nobody promised at intervals
 * nobody documented.
 *
 * The name says webhook and the job no longer does — deliberately, and not
 * because renaming was overlooked. The class name is the payload of every job
 * already sitting in Redis and of every failed_jobs row an operator might
 * replay; a rename turns those into "class not found" at the moment somebody
 * is trying to recover them. §9's own framing settles it too: the delivery
 * table is common and only the transport changes, so this job was always the
 * common half. `DeliverToChannelJob` can happen on a day when the queue is
 * drained and nothing is in flight, which is a deployment decision rather than
 * a refactor.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $deliveryId) {}

    public function handle(ChannelPublisherRegistry $publishers, CurrentProject $current): void
    {
        $delivery = WebhookDelivery::acrossProjects()->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        // Under the delivery's own tenant: every read below is scoped and fails
        // closed, and a queued job may arrive with no project in context.
        $current->run($delivery->project_id, function () use ($publishers, $delivery): void {
            /** @var WebhookDelivery|null $fresh */
            $fresh = WebhookDelivery::query()->with('channel')->whereKey($delivery->getKey())->first();

            if ($fresh !== null) {
                // The row says where it is going; the registry says who takes
                // it there. Nothing in the queued payload had to know.
                $publishers->forDelivery($fresh)->attempt($fresh);
            }
        });
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['publish', "delivery:{$this->deliveryId}"];
    }
}
