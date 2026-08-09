<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenSocialPresenceIsOff;
use App\Enums\ContentItemState;
use App\Enums\ProjectStatus;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SocialPlan;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan social:kill-expired` — §5's kill, made real.
 *
 * > Реактивная полоса — с TTL на юните: черновик, не попавший в окно,
 * > **убивается, а не публикуется позже**. Автоматический комментарий к
 * > позавчерашней новости хуже молчания.
 *
 * A delete and not a state, and the argument is {@see SignalsReapCommand}'s.
 * There is no `killed` state in {@see ContentItemState} and adding
 * one would be adding a terminal state to a machine four phases read, for a row
 * that has no history worth keeping: an unpublished reaction that missed its
 * window produced nothing, was seen by nobody, and its cause is still in
 * `signals` where §3's attribution wants it. What is worth keeping is the fact
 * that it happened, and that goes on the week's {@see SocialPlan} row rather
 * than into the deleted row — §7 asks what the engine did not do and why, and
 * "it was going to say something about Tuesday's news and Tuesday passed" is
 * exactly that.
 *
 * **Only what never went live.** A published post is not killed by its own TTL;
 * the TTL was about whether it was still worth *publishing*, and deleting a
 * post that is already on the platform would delete our record of something the
 * public can still read.
 *
 * **Hourly is enough, but not because nothing else could take it first.**
 * `publish:approved` is scoped to `roots()` and so cannot pick up a social post
 * at all today, which means there is no race to lose right now. When the social
 * publisher of §9 arrives it must ask the TTL itself before sending, rather
 * than trusting this command to have got there first — a reaper is a floor, not
 * a lock.
 */
class SocialKillExpiredCommand extends Command
{
    use SkipsWhenSocialPresenceIsOff;

    protected $signature = 'social:kill-expired
        {project? : Project slug or id. Omitted, every active project.}';

    protected $description = 'Delete reactive social drafts that missed their window (§5)';

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

        if ($projects->isEmpty()) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        $killed = 0;

        foreach ($projects as $project) {
            $killed += $current->run($project, fn (): int => $this->kill($project));
        }

        $this->components->info("Killed {$killed} draft(s) that missed their window.");

        return self::SUCCESS;
    }

    private function kill(Project $project): int
    {
        $expired = ContentItem::query()
            ->social()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNull('published_at')
            ->get();

        $killed = 0;

        foreach ($expired as $unit) {
            if ($unit->state->isLive()) {
                // Belt and braces against a row whose `published_at` was never
                // stamped: live is live, and §5's kill is about drafts.
                continue;
            }

            $this->record($project, $unit);

            $unit->delete();
            $killed++;
        }

        return $killed;
    }

    /**
     * Tell §7's summary what disappeared.
     *
     * Written to the plan row of the week the slot stood in, which is where the
     * refusal belongs: the decision not to publish it is part of that week's
     * story. A unit with no slot — or one from a week nobody planned — leaves a
     * log line and nothing else, because inventing a plan row for a week the
     * planner never ran would put a ceiling and a floor on the record that no
     * governor ever decided.
     */
    private function record(Project $project, ContentItem $unit): void
    {
        Log::info('A reactive social draft missed its window and was killed', [
            'project' => $project->slug,
            'unit' => $unit->slug,
            'band' => $unit->social_band?->value,
            'expired_at' => $unit->expires_at?->toIso8601String(),
        ]);

        $plan = SocialPlan::forWeek($project, $unit->slot_at ?? $unit->expires_at);

        $plan?->note(
            'killed',
            "“{$unit->title}” missed its window and was killed rather than published late (§5).",
        );
    }
}
