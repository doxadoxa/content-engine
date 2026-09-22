<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PagePublicationOperation;
use App\Publishing\Pages\DispatchPageOperation;
use Illuminate\Console\Command;

class ReconcilePagePublications extends Command
{
    protected $signature = 'pages:reconcile-publications';

    protected $description = 'Resume stranded or due native page operations under their original identities';

    public function handle(): int
    {
        PagePublicationOperation::acrossProjects()->whereNull('committed_at')
            ->whereIn('status', ['queued', 'sending', 'outcome_unknown'])
            ->where('updated_at', '<', now()->subMinutes(2))
            ->where(fn ($query) => $query->where('status', 'queued')->orWhere('status', 'sending')->orWhere('retry_at', '<=', now()))
            ->chunkById(100, function ($operations): void {
                foreach ($operations as $operation) {
                    DispatchPageOperation::dispatch($operation->id)->onQueue((string) config('publishing.queue', 'pipeline'));
                }
            });

        return self::SUCCESS;
    }
}
