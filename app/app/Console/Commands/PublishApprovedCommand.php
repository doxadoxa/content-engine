<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Entitlements;
use App\Enums\ProjectStatus;
use App\Models\ArticleSchedule;
use App\Models\Project;
use App\Publishing\Articles\ArticleSchedules;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;

/**
 * `php artisan publish:approved <project>` — the event that §4's publish
 * pipeline reacts to, until phase 7 gives approval a button.
 *
 * Scheduled articles only: an article is delivered once its schedule's
 * `publish_at` has passed. §5.4 makes auto-publish a project privilege, and
 * even with it on nothing here reaches a reader without having been approved
 * first — the flag decides whether approval happens automatically, not whether
 * it happens.
 */
class PublishApprovedCommand extends Command
{
    protected $signature = 'publish:approved
        {project? : Project slug or id. Omitted, every active project.}
        {--unit= : A single unit id, instead of everything due}';

    protected $description = 'Deliver approved content units to verified automatic channels';

    public function __construct(private readonly Entitlements $entitlements)
    {
        parent::__construct();
    }

    public function handle(CurrentProject $current): int
    {
        // Optional, because this runs on a schedule as well as by hand and a
        // scheduled command has nobody to name a project. Required, it failed
        // silently every half hour and nothing was ever delivered.
        $handle = $this->argument('project');

        if (! is_string($handle) || $handle === '') {
            return $this->everyProject($current);
        }

        $project = Project::query()->where('slug', $handle)->first()
            ?? Project::query()->whereKey($handle)->first();

        if ($project === null) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        return $this->forProject($project, $current);
    }

    private function everyProject(CurrentProject $current): int
    {
        // Publishing survives a failed payment and stops only once the
        // subscription is over. That asymmetry with generation is the whole of
        // the dunning policy: we stop spending our money at once, and stop
        // delivering theirs at the end. An article somebody approved was
        // already paid for, and holding it back turns a billing problem into a
        // support incident.
        $projects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->get()
            ->filter(fn (Project $project): bool => $this->entitlements->for($project)->mayPublish())
            ->values();

        foreach ($projects as $project) {
            $this->forProject($project, $current);
        }

        return self::SUCCESS;
    }

    private function forProject(Project $project, CurrentProject $current): int
    {
        return $current->run($project, function () use ($project): int {
            $schedules = app(ArticleSchedules::class);
            $schedules->resolveTargets($project);
            $query = ArticleSchedule::query()->whereIn('status', ['active', 'blocked'])
                ->where('publish_at', '<=', now()->toIso8601String())
                ->when($this->option('unit'), fn ($q, $id) => $q->where('content_item_id', $id));
            $queued = 0;
            foreach ($query->with('contentItem')->get() as $schedule) {
                $queued += count($schedules->dispatch($schedule->contentItem));
            }
            $this->components->info("Queued {$queued} scheduled article delivery(s).");

            return self::SUCCESS;
        });
    }
}
