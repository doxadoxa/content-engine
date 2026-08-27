<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Entitlements;
use App\Enums\ContentItemState;
use App\Enums\ProjectStatus;
use App\Models\ContentItem;
use App\Models\Project;
use App\Publishing\PublishToChannels;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;

/**
 * `php artisan publish:approved <project>` — the event that §4's publish
 * pipeline reacts to, until phase 7 gives approval a button.
 *
 * Approved units only. §5.4 makes auto-publish a project privilege, and even
 * with it on nothing here reaches a reader without a human having approved it
 * first — the flag decides whether approval happens automatically, not whether
 * it happens.
 */
class PublishApprovedCommand extends Command
{
    protected $signature = 'publish:approved
        {project? : Project slug or id. Omitted, every active project.}
        {--unit= : A single unit id, instead of everything approved}';

    protected $description = 'Deliver approved content units to verified automatic channels';

    public function __construct(private readonly Entitlements $entitlements)
    {
        parent::__construct();
    }

    public function handle(PublishToChannels $channels, CurrentProject $current): int
    {
        // Optional, because this runs on a schedule as well as by hand and a
        // scheduled command has nobody to name a project. Required, it failed
        // silently every half hour and nothing was ever delivered.
        $handle = $this->argument('project');

        if (! is_string($handle) || $handle === '') {
            return $this->everyProject($channels, $current);
        }

        $project = Project::query()->where('slug', $handle)->first()
            ?? Project::query()->whereKey($handle)->first();

        if ($project === null) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        return $this->forProject($project, $channels, $current);
    }

    private function everyProject(PublishToChannels $channels, CurrentProject $current): int
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
            $this->forProject($project, $channels, $current);
        }

        return self::SUCCESS;
    }

    private function forProject(Project $project, PublishToChannels $channels, CurrentProject $current): int
    {
        return $current->run($project, function () use ($channels): int {
            // Articles, and this scope is the whole of the guard: the scheduled
            // run publishes whatever is approved, and since §3 an approved unit
            // may be a 300-character social post with no parent. Handed to a
            // webhook receiver it becomes a page with a slug and an article's
            // schema.org type. `roots()` is where 12.1 put the answer to "is
            // this an article", so it is asked here rather than restated.
            //
            // `--unit` stays unscoped. Naming an id is a person saying which
            // one they mean, and {@see PublishToChannels} still refuses to send
            // a post somewhere that would read it as an article — and, since
            // 12.6, still refuses to send one the governor has no room for.
            $units = ContentItem::query()
                ->when($this->option('unit'), fn ($q, $id) => $q->whereKey($id))
                ->when(! $this->option('unit'), fn ($q) => $q->roots()->inState(ContentItemState::Approved))
                ->get();

            if ($units->isEmpty()) {
                $this->components->info('Nothing is waiting to be published.');

                return self::SUCCESS;
            }

            $queued = 0;

            foreach ($units as $unit) {
                // `--unit` is unscoped by design — naming an id is a person
                // saying which one they mean — and until 12.6 that made this
                // command a way to walk a social post straight past §4.3's
                // ceiling. It is not the operator's judgement that is being
                // overridden here: the ceiling is arithmetic about what the
                // account can carry, and the reason is printed so the answer
                // is a sentence rather than a shrug.
                $refusal = $channels->refusal($unit);

                if ($refusal !== null) {
                    $this->components->warn("{$unit->slug}: {$refusal}.");

                    continue;
                }

                $deliveries = $channels->publishAutomatically($unit);

                if ($deliveries === []) {
                    $this->components->warn("{$unit->slug}: no enabled channel to publish to.");

                    continue;
                }

                $queued += count($deliveries);
                $this->components->twoColumnDetail($unit->slug, count($deliveries).' delivery(s)');
            }

            $this->components->info("Queued {$queued} delivery(s).");

            return self::SUCCESS;
        });
    }
}
