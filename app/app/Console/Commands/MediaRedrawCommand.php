<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\MediaDisk;
use App\Media\MediaWriteFailed;
use App\Media\RelocateMedia;
use App\Models\Asset;
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
 * article and a look at the result. It counts attempts, not successes: the
 * vendor bills before the file is stored, so a draw that failed afterwards
 * still cost money, and a cap that only counted what worked would let a
 * broken bucket charge for every picture in the project.
 *
 * A write the media disk refuses stops the run. Every later file goes to the
 * same disk, and each would be drawn and paid for before failing the same way.
 *
 * The spend is printed rather than metered: there is no pipeline run to charge
 * it to, and the cost reports read pipeline steps.
 */
class MediaRedrawCommand extends Command
{
    /** A project's outcome when the media disk refused a write and nothing more should be tried. */
    private const int HALTED = 2;

    protected $signature = 'media:redraw
        {project? : Project slug or id. Omitted, every project.}
        {--unit= : A single unit id}
        {--limit= : Attempt at most this many draws; copies are free and not counted}
        {--dry : Say what would be copied and drawn, change nothing}';

    protected $description = 'Move pictures onto the media disk, drawing again any whose file is gone';

    private ?int $limit = null;

    private int $attempted = 0;

    private int $drawn = 0;

    private int $spent = 0;

    public function handle(CurrentProject $current, RelocateMedia $media): int
    {
        // The console application keeps one instance of a command, so a second
        // call in the same process — Artisan::call, a test — would otherwise
        // start with the last run's count against its limit.
        $this->limit = null;
        $this->attempted = 0;
        $this->drawn = 0;
        $this->spent = 0;

        // Refused rather than ignored: this is the cost cap, and a typo that
        // quietly turned it into "no cap" would spend exactly what it was
        // typed to prevent.
        $limit = $this->option('limit');

        if ($limit !== null) {
            $limit = filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($limit === false) {
                $this->components->error('--limit must be a whole number, 0 or more.');

                return self::FAILURE;
            }

            $this->limit = $limit;
        }

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

        $status = self::SUCCESS;

        foreach ($projects as $project) {
            $outcome = $current->run($project, fn (): int => $this->forProject($project, $media));

            if ($outcome !== self::SUCCESS) {
                $status = self::FAILURE;
            }

            if ($outcome === self::HALTED) {
                break;
            }
        }

        if (! $this->option('dry')) {
            $this->components->info(sprintf(
                'Drew %d of %d attempted picture(s), about $%.2f.',
                $this->drawn,
                $this->attempted,
                $this->spent / 1_000_000,
            ));
        }

        return $status;
    }

    /** @return int SUCCESS, FAILURE when a file could not be moved, or HALTED */
    private function forProject(Project $project, RelocateMedia $media): int
    {
        $unit = $this->option('unit');
        $groups = $media->stale(is_string($unit) && $unit !== '' ? $unit : null);

        if ($groups === []) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail("<options=bold>{$project->slug}</>", count($groups).' file(s)');

        if ($this->option('dry')) {
            $this->plan($groups, $media);

            return self::SUCCESS;
        }

        $outcome = self::SUCCESS;
        $queued = 0;

        foreach ($groups as $rows) {
            $label = $this->label($rows);
            $busy = $media->busy($rows);

            if ($busy !== null) {
                $this->components->twoColumnDetail($label, "<fg=yellow>skipped</> {$busy}");

                continue;
            }

            $copyable = $media->copyable($rows);

            if (! $copyable && $this->limit !== null && $this->attempted >= $this->limit) {
                $this->components->twoColumnDetail($label, '<fg=yellow>skipped</> --limit reached');

                continue;
            }

            if (! $copyable) {
                $this->attempted++;
            }

            try {
                $result = $media->relocate($rows);
            } catch (MediaWriteFailed $e) {
                if ($e->wasPaidFor()) {
                    $this->spent += (int) $e->spendMicros;
                }

                $this->components->twoColumnDetail($label, '<fg=red>failed</> '.$e->getMessage());
                $this->components->error('The media disk refused a write. Stopping before anything else is paid for.');
                $outcome = self::HALTED;

                // Out of the loop rather than out of the method, so the
                // updates already queued for this project are still reported.
                break;
            } catch (Throwable $e) {
                $outcome = self::FAILURE;
                $this->components->twoColumnDetail($label, '<fg=red>failed</> '.$e->getMessage());

                continue;
            }

            if ($result['redrawn']) {
                $this->drawn++;
                $this->spent += $result['cost'];
            }

            $queued += $result['queued'];

            $this->components->twoColumnDetail($label, $result['redrawn'] ? '<fg=green>drawn</>' : '<fg=green>copied</>');
        }

        if ($queued > 0) {
            $this->components->info("Queued {$queued} update(s) to channels that already had these articles.");
        }

        return $outcome;
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
