<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use App\Publishing\ChannelPublisherRegistry;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;

/**
 * `php artisan publish:replay <delivery>` — §6.2's replay, from code. The
 * dashboard button is phase 7 and calls the same publisher.
 */
class PublishReplayCommand extends Command
{
    protected $signature = 'publish:replay {delivery : The delivery_id (UUID) to replay}';

    protected $description = 'Re-send a delivery\'s stored snapshot under a new delivery id';

    public function handle(ChannelPublisherRegistry $publishers, CurrentProject $current): int
    {
        $delivery = WebhookDelivery::acrossProjects()
            ->where('delivery_id', $this->argument('delivery'))
            ->first();

        if ($delivery === null) {
            $this->components->error('No delivery with that id.');

            return self::FAILURE;
        }

        $replay = $current->run(
            $delivery->project_id,
            fn (): WebhookDelivery => $publishers->forDelivery($delivery)->replay($delivery),
        );

        $this->components->info("Replayed as {$replay->delivery_id}.");

        return self::SUCCESS;
    }
}
