<?php

declare(strict_types=1);

namespace App\Publishing\Pages;

use App\Models\PagePublicationOperation;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchPageOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $operationId) {}

    public function handle(PageOperationDispatcher $dispatcher, CurrentProject $current): void
    {
        $operation = PagePublicationOperation::acrossProjects()->find($this->operationId);
        if ($operation !== null) {
            $current->run($operation->project_id, fn () => $dispatcher->attempt($operation));
        }
    }
}
