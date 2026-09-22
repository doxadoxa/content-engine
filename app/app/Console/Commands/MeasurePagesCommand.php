<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Feedback\Measurements\ReadStatus;
use App\Feedback\Measurements\SynchronizePageMeasurements;
use App\Feedback\Measurements\SyncPageMeasurementsJob;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SitePage;
use App\Pages\RegisterPublishedArticle;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;

final class MeasurePagesCommand extends Command
{
    protected $signature = 'pages:measure {--project= : Project id or slug} {--sync : Read now instead of queueing}';

    protected $description = 'Read observed Search Console and supplementary Analytics history for tracked website pages';

    public function handle(CurrentProject $current, SynchronizePageMeasurements $sync): int
    {
        $projects = Project::query()->where('status', ProjectStatus::Active);
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
            $current->run($project, function () use ($project, $sync, &$failed): void {
                ContentItem::query()->where('state', 'published')->whereNotNull('public_url')
                    ->whereNotIn('id', SitePage::query()->whereNotNull('content_item_id')->select('content_item_id'))
                    ->each(fn (ContentItem $item) => app(RegisterPublishedArticle::class)->register($item));
                if (! SitePage::query()->tracked()->exists()) {
                    $this->line($project->slug.': no tracked pages.');

                    return;
                }
                if (! $this->option('sync')) {
                    SyncPageMeasurementsJob::dispatch($project->id);
                    $this->line($project->slug.': measurement queued.');

                    return;
                }
                $reads = $sync->sync($project);
                if ($reads === []) {
                    $this->line($project->slug.': a measurement is already running.');
                }
                foreach ($reads as $source => $read) {
                    $this->line($project->slug.' '.$source.': '.$read->status->value.($read->reason === null ? '' : ' — '.$read->reason));
                    $failed = $failed || in_array($read->status, [ReadStatus::Failed, ReadStatus::Partial, ReadStatus::Incompatible], true);
                }
            });
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
