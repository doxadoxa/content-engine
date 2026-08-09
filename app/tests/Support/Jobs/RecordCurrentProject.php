<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Appends the project that was current when it ran, so a test can assert both
 * that the tenant survived the queue payload and that it did not leak into the
 * next job the same worker picks up.
 */
class RecordCurrentProject implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'test.projects_seen_by_jobs';

    /**
     * @return list<string>
     */
    public static function seen(): array
    {
        /** @var list<string> $seen */
        $seen = Cache::get(self::CACHE_KEY, []);

        return $seen;
    }

    public function handle(CurrentProject $currentProject): void
    {
        Cache::forever(self::CACHE_KEY, [...self::seen(), $currentProject->id() ?? 'none']);
    }
}
