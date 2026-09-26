<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

use App\Enums\ProjectStatus;
use App\Models\MeasurementRead;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads the whole Search Console property for one project.
 *
 * Unique per project, so the performance page can ask for it on every poll
 * without queueing a second read behind the first.
 */
final class SyncSiteSearchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How long a request is remembered. Past this the performance page may ask
     * again on its own; before it, a request nobody answered is reported as
     * failed after {@see SiteSearchReport::STALLED_AFTER_MINUTES} rather than
     * re-queued on every poll.
     */
    private const int REQUEST_REMEMBERED_FOR = 86400;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1900;

    public bool $failOnTimeout = true;

    public string $batchId;

    public function __construct(public string $projectId)
    {
        $this->batchId = (string) Str::ulid();
        $this->onQueue('pipeline-expensive');
    }

    public function uniqueId(): string
    {
        return $this->projectId;
    }

    /**
     * Whether a project's own Search Console should be read at all.
     *
     * Everything that is not paused or archived. A project on a trial, or one
     * still finishing onboarding, is `ProjectStatus::Active` — `status` only
     * ever becomes Paused when an owner pauses it or billing lapses — so the
     * status check alone would already admit them; archived projects keep
     * their status and have to be excluded by `archived_at`. Reading costs
     * nothing but a request to Google on the owner's own grant, so there is no
     * reason to hold it back from a project that is merely new.
     */
    public static function eligible(Project $project): bool
    {
        return $project->status !== ProjectStatus::Paused && $project->archived_at === null;
    }

    /**
     * Queue a read and remember that one was asked for.
     *
     * The marker is what lets the screen tell "queued a moment ago" from "asked
     * for and never answered": a unique job that was never picked up leaves no
     * MeasurementRead behind, and without the marker both look like nothing.
     */
    public static function request(Project $project): bool
    {
        if (! self::eligible($project)) {
            return false;
        }
        Cache::put(self::markerKey($project->id), now()->getTimestamp(), self::REQUEST_REMEMBERED_FOR);
        self::dispatch($project->id);

        return true;
    }

    /** When a read was last asked for, if it is still remembered. */
    public static function requestedAt(string $projectId): ?Carbon
    {
        $at = Cache::get(self::markerKey($projectId));

        return is_int($at) ? Carbon::createFromTimestamp($at) : null;
    }

    /** Forget the request, so the performance page's self-heal asks again. */
    public static function forgetRequest(string $projectId): void
    {
        Cache::forget(self::markerKey($projectId));
    }

    public function handle(SynchronizeSiteSearch $sync): void
    {
        $project = Project::query()->find($this->projectId);
        if ($project === null || ! self::eligible($project)) {
            return;
        }
        $sync->sync($project, batchId: $this->batchId);
    }

    public function failed(?Throwable $exception): void
    {
        app(CurrentProject::class)->run($this->projectId, function (): void {
            MeasurementRead::query()->where('metadata->batch_id', $this->batchId)->where('status', ReadStatus::Reading)
                ->update(['status' => ReadStatus::Failed, 'reason' => 'The site search read did not finish. Run it again.', 'finished_at' => now()]);
        });
    }

    private static function markerKey(string $projectId): string
    {
        return 'site-search:dispatched:'.$projectId;
    }
}
