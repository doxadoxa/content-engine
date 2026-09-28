<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\MediaDisk;
use App\Media\RelocateMedia;
use App\Models\Asset;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * `php artisan media:redraw` — put every picture on the configured media disk.
 *
 * For a deployment that generated images before it had a bucket: the rows say
 * `public`, the files were on a container that is gone, and every article
 * shows a broken image. See {@see RelocateMedia} for what happens to each
 * file; this decides which files, prints what it will cost, and does it.
 *
 * `--dry` first. A picture that has to be drawn again is the most expensive
 * thing an article buys, and this can touch every article a project has.
 * `--limit` caps how many are drawn in one run, so a first real run can be one
 * article and a look at the result.
 *
 * The spend is printed rather than metered: there is no pipeline run to charge
 * it to, and the cost reports read pipeline steps.
 */
class MediaRedrawCommand extends Command
{
    protected $signature = 'media:redraw
        {project? : Project slug or id. Omitted, every project.}
        {--unit= : A single unit id}
        {--limit= : Draw at most this many pictures; copies are free and not counted}
        {--dry : Say what would be copied and drawn, change nothing}';

    protected $description = 'Move pictures onto the media disk, drawing again any whose file is gone';

    private int $drawn = 0;

    private int $spent = 0;

    public function handle(CurrentProject $current, RelocateMedia $media): int
    {
        $handle = $this->argument('project');

        if (is_string($handle) && $handle !== '') {
            $project = Project::query()->where('slug', $handle)->first()
                ?? Project::query()->whereKey($handle)->first();

            if ($project === null) {
                $this->components->error('No project with that slug or id.');

                return self::FAILURE;
            }

            $projects = new Collection([$project]);
        } else {
            $projects = Project::query()->orderBy('slug')->get();
        }

        $this->components->info('Media disk: '.MediaDisk::name());

        $failed = false;

        foreach ($projects as $project) {
            $failed = ! $current->run($project, fn (): bool => $this->forProject($project, $media)) || $failed;
        }

        if (! $this->option('dry')) {
            $this->components->info(sprintf('Drew %d picture(s), about $%.2f.', $this->drawn, $this->spent / 1_000_000));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return bool false when any file could not be moved */
    private function forProject(Project $project, RelocateMedia $media): bool
    {
        $unit = $this->option('unit');
        $groups = $media->stale(is_string($unit) && $unit !== '' ? $unit : null);

        if ($groups === []) {
            return true;
        }

        $this->newLine();
        $this->components->twoColumnDetail("<options=bold>{$project->slug}</>", count($groups).' file(s)');

        if ($this->option('dry')) {
            $this->plan($groups, $media);

            return true;
        }

        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;
        $ok = true;
        /** @var array<string, true> $touched */
        $touched = [];

        foreach ($groups as $rows) {
            $label = $this->label($rows);
            $busy = $media->busy($rows);

            if ($busy !== null) {
                $this->components->twoColumnDetail($label, "<fg=yellow>skipped</> {$busy}");

                continue;
            }

            $copyable = $media->copyable($rows);

            if (! $copyable && $limit !== null && $this->drawn >= $limit) {
                $this->components->twoColumnDetail($label, '<fg=yellow>skipped</> --limit reached');

                continue;
            }

            try {
                $result = $media->relocate($rows);
            } catch (Throwable $e) {
                $ok = false;
                $this->components->twoColumnDetail($label, '<fg=red>failed</> '.$e->getMessage());

                continue;
            }

            if ($result['redrawn']) {
                $this->drawn++;
                $this->spent += $result['cost'];
            }

            foreach ($result['units'] as $id) {
                $touched[$id] = true;
            }

            $this->components->twoColumnDetail($label, $result['redrawn'] ? '<fg=green>drawn</>' : '<fg=green>copied</>');
        }

        $queued = 0;

        foreach (ContentItem::query()->whereKey(array_keys($touched))->get() as $item) {
            $queued += $media->redeliver($item);
        }

        if ($queued > 0) {
            $this->components->info("Queued {$queued} update(s) to channels that already had these articles.");
        }

        return $ok;
    }

    /** @param  list<Collection<int, Asset>>  $groups */
    private function plan(array $groups, RelocateMedia $media): void
    {
        $draw = 0;

        foreach ($groups as $rows) {
            $copyable = $media->copyable($rows);
            $draw += $copyable ? 0 : 1;
            $busy = $media->busy($rows);

            $this->components->twoColumnDetail(
                $this->label($rows),
                $busy !== null ? "<fg=yellow>would skip</> {$busy}" : ($copyable ? 'copy' : 'draw'),
            );
        }

        $micros = (int) config('media.atlas.cost_micros', 40_000);
        $this->components->info(sprintf('Would draw %d picture(s), about $%.2f.', $draw, $draw * $micros / 1_000_000));
    }

    /** @param  Collection<int, Asset>  $rows */
    private function label(Collection $rows): string
    {
        /** @var Asset $first */
        $first = $rows->first();
        $title = $first->contentItem->title ?? $first->content_item_id;
        $shared = $rows->count() > 1 ? ' ×'.$rows->count() : '';

        return "{$first->role->value}{$shared} · {$title}";
    }
}
