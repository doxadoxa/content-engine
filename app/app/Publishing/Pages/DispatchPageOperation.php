<?php

declare(strict_types=1);

namespace App\Publishing\Pages;

use App\Models\PagePublicationOperation;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One attempt at one native page operation.
 *
 * On the publishing lane, like a delivery and for the same reason: it is one
 * outbound request somebody is waiting on. Its own queue name on that lane
 * (`publishing.pages_queue`), so the stranded-delivery sweep's count of
 * waiting deliveries is not inflated by page work. Set here so none of the
 * four dispatch sites can leave it on the pipeline's queue.
 */
class DispatchPageOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $operationId)
    {
        $this->onConnection((string) config('publishing.connection'));
        $this->onQueue((string) config('publishing.pages_queue'));
    }

    public function handle(PageOperationDispatcher $dispatcher, CurrentProject $current): void
    {
        $operation = PagePublicationOperation::acrossProjects()->find($this->operationId);
        if ($operation !== null) {
            $current->run($operation->project_id, fn () => $dispatcher->attempt($operation));
        }
    }
}
