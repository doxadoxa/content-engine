<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Feedback\Measurements\ReadStatus;
use App\Feedback\Measurements\SynchronizePageMeasurements;
use App\Feedback\Measurements\SynchronizeSiteSearch;
use App\Feedback\Measurements\SyncPageMeasurementsJob;
use App\Feedback\Measurements\SyncSiteSearchJob;
use App\Integrations\Google\GooglePanel;
use App\Models\ContentItem;
use App\Models\MeasurementRead;
use App\Models\Project;
use App\Models\SitePage;
use App\Pages\RegisterPublishedArticle;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;

final class MeasurePagesCommand extends Command
{
    protected $signature = 'pages:measure {--project= : Project id or slug} {--sync : Read now instead of queueing}';

    protected $description = 'Read the whole Search Console property, plus observed search and supplementary Analytics history for tracked website pages';

    public function handle(CurrentProject $current, SynchronizePageMeasurements $sync, SynchronizeSiteSearch $siteSync, GooglePanel $google): int
    {
        // Active and not archived: exactly what SyncSiteSearchJob::eligible()
        // admits, since a project is only ever Active or Paused.
        $projects = Project::query()->where('status', ProjectStatus::Active)->whereNull('archived_at');
        $handle = $this->option('project');
        if (is_string($handle) && $handle !== '') {
            $projects->where(fn ($query) => $query->where('id', $handle)->orWhere('slug', $handle));
        }
        $selected = $projects->get();
        if ($selected->isEmpty()) {
            $this->components->info('No matching active projects.');

            return is_string($handle) && $handle !== '' ? self::FAILURE : self::SUCCESS;
        }
        $failed = false;
        foreach ($selected as $project) {
            $current->run($project, function () use ($project, $sync, $siteSync, $google, &$failed): void {
                ContentItem::query()->where('state', 'published')->whereNotNull('public_url')
                    ->whereNotIn('id', SitePage::query()->whereNotNull('content_item_id')->select('content_item_id'))
                    ->each(fn (ContentItem $item) => app(RegisterPublishedArticle::class)->register($item));
                // The whole property first, and regardless of tracked pages:
                // it is what a project with none still has to look at.
                if ($google->connectionState($project)['search_console']) {
                    if (! $this->option('sync')) {
                        SyncSiteSearchJob::request($project);
                        $this->line($project->slug.': site search queued.');
                    } else {
                        $failed = $this->report($project->slug, $siteSync->sync($project), 'a site search read') || $failed;
                    }
                }
                if (! SitePage::query()->tracked()->exists()) {
                    $this->line($project->slug.': no tracked pages.');

                    return;
                }
                if (! $this->option('sync')) {
                    SyncPageMeasurementsJob::dispatch($project->id);
                    $this->line($project->slug.': measurement queued.');

                    return;
                }
                $failed = $this->report($project->slug, $sync->sync($project), 'a measurement') || $failed;
            });
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Print each read's outcome; true when any of them fell short.
     *
     * @param  array<string, MeasurementRead>  $reads
     */
    private function report(string $slug, array $reads, string $what): bool
    {
        if ($reads === []) {
            $this->line($slug.': '.$what.' is already running.');
        }
        $failed = false;
        foreach ($reads as $source => $read) {
            $this->line($slug.' '.$source.': '.$read->status->value.($read->reason === null ? '' : ' — '.$read->reason));
            $failed = $failed || in_array($read->status, [ReadStatus::Failed, ReadStatus::Partial, ReadStatus::Incompatible], true);
        }

        return $failed;
    }
}
