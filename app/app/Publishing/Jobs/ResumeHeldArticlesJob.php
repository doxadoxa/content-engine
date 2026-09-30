<?php

declare(strict_types=1);

namespace App\Publishing\Jobs;

use App\Models\Channel;
use App\Publishing\HeldArticles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * {@see HeldArticles::resume()} once more, for the articles an attempt was
 * holding the first time: that attempt may have decided to wait on the
 * website as it was a moment before it was fixed. On the publishing lane,
 * like every other delivery job.
 */
class ResumeHeldArticlesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $channelId)
    {
        $this->onConnection((string) config('publishing.connection'));
        $this->onQueue((string) config('publishing.queue'));
    }

    public function handle(HeldArticles $held): void
    {
        $channel = Channel::acrossProjects()->find($this->channelId);

        if ($channel instanceof Channel) {
            $held->resume($channel);
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['publish', "channel:{$this->channelId}"];
    }
}
