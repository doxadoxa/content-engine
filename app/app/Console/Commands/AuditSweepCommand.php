<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\SiteAuditStarter;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `php artisan audit:sweep` — re-read each project's site when its last reading
 * is stale.
 *
 * Its own schedule entry rather than a branch of `engine:tick`, and for the
 * reason the tick's own docblock gives: that command refuses to start anything
 * while the article contour is busy, which is right for work that feeds itself
 * and wrong for this. A project drafting a long article has exactly as much
 * reason to know its sitemap is broken as an idle one — more, since the article
 * is about to be published into that sitemap.
 *
 * Runs daily and starts almost nothing. The freshness question is asked per
 * project by {@see SiteAuditStarter::startIfDue()} against
 * `audit.refresh_after_days`, so a daily wakeup produces a weekly crawl per
 * project, spread across the week by when each project last had one rather than
 * arriving as a Monday morning thundering herd against seven customer sites.
 *
 * Active projects only, unlike `pipeline:reap`. A paused project is not being
 * written for, and crawling somebody's site to fill in a screen nobody is
 * looking at is an imposition with no purpose.
 */
class AuditSweepCommand extends Command
{
    protected $signature = 'audit:sweep
        {--project= : Only this project, by slug or id}
        {--force : Start a sweep even if the last one is recent}
        {--dry : Say what would start, start nothing}';

    protected $description = 'Audit each project site whose last reading has gone stale';

    public function handle(SiteAuditStarter $audits): int
    {
        $projects = $this->projects();

        if ($projects === []) {
            $this->components->info('No active projects with a website.');

            return self::SUCCESS;
        }

        $started = 0;

        foreach ($projects as $project) {
            if ($this->option('dry')) {
                $this->line("  {$project->slug}: would consider a sweep of {$project->website_url}");

                continue;
            }

            try {
                $run = $this->option('force')
                    ? $audits->start($project)
                    : $audits->startIfDue($project);
            } catch (Throwable $e) {
                // One project's bad state must not stop the sweep reaching the
                // rest — the same rule `engine:tick` follows, and it matters
                // more here because a site that cannot be audited is exactly
                // the kind that throws.
                Log::warning('A site audit could not be started', [
                    'project' => $project->slug,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            if ($run === null) {
                continue;
            }

            $this->line("  {$project->slug}: auditing {$project->website_url}");
            $started++;
        }

        if ($started > 0) {
            $this->components->info("Started {$started} site audit(s).");
        }

        return self::SUCCESS;
    }

    /** @return list<Project> */
    private function projects(): array
    {
        $handle = $this->option('project');

        /** @var list<Project> $projects */
        $projects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->whereNotNull('website_url')
            ->where('website_url', '!=', '')
            ->when(
                is_string($handle) && $handle !== '',
                fn ($query) => $query->where(fn ($q) => $q->where('slug', $handle)->orWhere('id', $handle)),
            )
            ->get()
            ->all();

        return $projects;
    }
}
