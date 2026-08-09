<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenSocialPresenceIsOff;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Signal;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;

/**
 * `php artisan signals:reap` — take out the signals that died (§5).
 *
 * {@see Signal::scopeReapable()} has existed since the table did and nothing
 * called it. Its docblock is the specification and this command is nothing more
 * than a caller: **only rows with an `expires_at` that has passed, never
 * consumed, and pointed at by no content unit.** Each of those three is a row a
 * naive reaper would destroy, and the third is the expensive one —
 * `content_items.signal_id` is `nullOnDelete`, so deleting a signal a published
 * article points at does not fail, it quietly blanks the column, and §3's
 * per-source attribution loses the news contour first. That is the exact
 * question §3 says the table exists to answer with a number rather than an
 * opinion.
 *
 * Which is also why this is a delete rather than an archive. A signal with no
 * window (a corpus gap, a seasonal curve) is never reaped at all, and one that
 * produced anything is never reaped either; what is left is a news item nobody
 * acted on inside its TTL, and §5 is explicit that such a thing is not
 * published later — it is killed.
 *
 * Per project rather than across them, so the tenant scope stays on. The table
 * is small and the reaper runs daily; there is no query here worth unscoping
 * for.
 */
class SignalsReapCommand extends Command
{
    use SkipsWhenSocialPresenceIsOff;

    protected $signature = 'signals:reap
        {project? : Project slug or id. Omitted, every active project.}';

    protected $description = 'Delete expired signals nothing used and nothing points at';

    public function handle(CurrentProject $current): int
    {
        if ($this->socialPresenceIsOff()) {
            return self::SUCCESS;
        }

        $handle = $this->argument('project');

        $projects = Project::query()
            ->when(
                is_string($handle) && $handle !== '',
                fn ($query) => $query->where(fn ($q) => $q->where('slug', $handle)->orWhere('id', $handle)),
                fn ($query) => $query->where('status', ProjectStatus::Active),
            )
            ->get();

        // Two different empties, and only one of them is wrong. A slug nobody
        // recognises is a typo and an error; no active projects at all is a
        // fresh installation, and the scheduled run hits it every night at 03:20
        // until somebody onboards something. Failing there put a red line in the
        // log for a state the engine is supposed to start in — and every other
        // command in this directory returns success for it.
        if ($projects->isEmpty()) {
            if (is_string($handle) && $handle !== '') {
                $this->components->error('No project with that slug or id.');

                return self::FAILURE;
            }

            $this->components->info('No active project to reap signals for.');

            return self::SUCCESS;
        }

        $reaped = 0;

        foreach ($projects as $project) {
            $reaped += $current->run(
                $project,
                // `reapable()` carries the whole judgement. Adding a condition
                // here would be a second definition of "safe to delete", and
                // the scope's docblock is where the reasoning lives.
                static fn (): int => Signal::query()->reapable()->delete(),
            );
        }

        $this->components->info("Reaped {$reaped} expired signal(s).");

        return self::SUCCESS;
    }
}
