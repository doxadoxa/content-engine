<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenSocialPresenceIsOff;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\PipelineRunStatus;
use App\Enums\ProjectStatus;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `php artisan social:draft <project>` — turn a slot that is coming due into a
 * post worth publishing (§4.3).
 *
 * The planner writes a week of slots and stops. Without this, `social_draft`
 * exists and nothing ever starts it — a week of times to publish at, and
 * nothing to publish. §11.3 is blunt about that class of gap: "Планировщик
 * движка пуст… Статья это терпит, соцкалендарь — нет."
 *
 * **The lead time is hours, not days.** {@see EngineTickCommand} drafts an
 * article a day ahead so there is an evening to read it in. A social slot is an
 * *instant* inside a duty window (§4.3), and a post drafted a day early is a
 * post written before the conversation it was going to join. Two hours is
 * enough for the model call, the image and an operator's glance, and short
 * enough that the reactive band still means something.
 *
 * **Its own entry on the schedule, hourly**, next to listening and for the same
 * reason: {@see EngineTickCommand::isBusy()} makes the article contour wait for
 * the article contour, and a slot whose duty window opens at 10:00 must not
 * miss it because a generation run is in flight.
 *
 * A slot already drafted, already published, or past its TTL is left alone —
 * §5 kills an expired reactive draft rather than publishing it late, and
 * {@see SocialKillExpiredCommand} owns that.
 */
class SocialDraftCommand extends Command
{
    use SkipsWhenSocialPresenceIsOff;

    /**
     * How far ahead of its slot a post is written.
     *
     * Long enough to absorb a retry and a slow image; short enough that the
     * post is about the week it goes out in.
     */
    private const int LEAD_HOURS = 2;

    /** Per project per pass, so one busy project cannot flood the queue. */
    private const int MAX_STARTS = 3;

    protected $signature = 'social:draft
        {project? : Project slug or id. Omitted, every active project.}';

    protected $description = 'Draft the social slots coming due: candidates, the guard and one survivor';

    public function handle(PipelineRunner $runner, CurrentProject $current): int
    {
        if ($this->socialPresenceIsOff()) {
            return self::SUCCESS;
        }

        $handle = $this->argument('project');

        if (! is_string($handle) || $handle === '') {
            foreach (Project::query()->where('status', ProjectStatus::Active)->get() as $project) {
                $this->draft($project, $runner, $current);
            }

            return self::SUCCESS;
        }

        $project = Project::query()->where('slug', $handle)->first()
            ?? Project::query()->whereKey($handle)->first();

        if ($project === null) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        $this->draft($project, $runner, $current);

        return self::SUCCESS;
    }

    private function draft(Project $project, PipelineRunner $runner, CurrentProject $current): void
    {
        $current->run($project, function () use ($project, $runner): void {
            if ($this->isDrafting()) {
                // Scoped to `social_draft`, like every other contour gate here:
                // each contour blocks only itself.
                return;
            }

            foreach ($this->due() as $slot) {
                try {
                    $runner->start('social_draft', $project, ['content_item_id' => $slot->getKey()], $slot->getKey());
                } catch (Throwable $e) {
                    // One bad slot must not stop the rest of the sweep.
                    Log::warning('A social draft could not start', [
                        'project' => $project->slug,
                        'slot' => $slot->getKey(),
                        'reason' => $e->getMessage(),
                    ]);

                    continue;
                }

                $this->components->twoColumnDetail(
                    $project->slug,
                    "drafting a {$slot->social_band?->value} slot for {$slot->slot_at?->toDateTimeString()}",
                );
            }
        });
    }

    /**
     * Slots close enough to need writing.
     *
     * `idea` only. A slot that already reached `draft` has been written, and one
     * the operator rejected is not something to write again on the next pass of
     * the hour.
     *
     * An expired slot is skipped rather than drafted and then killed: §5 says a
     * reactive draft that misses its window is killed, and spending eight model
     * calls to produce something for the reaper is the expensive way to obey it.
     *
     * @return list<ContentItem>
     */
    private function due(): array
    {
        $due = ContentItem::query()
            ->social()
            ->inState(ContentItemState::Idea)
            ->where('type', ContentItemType::SocialPost)
            ->whereNotNull('slot_at')
            ->where('slot_at', '<=', Carbon::now()->addHours(self::LEAD_HOURS))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->orderBy('slot_at')
            ->limit(self::MAX_STARTS)
            ->get()
            ->all();

        return array_values($due);
    }

    private function isDrafting(): bool
    {
        return PipelineRun::query()
            ->where('pipeline', 'social_draft')
            ->whereIn('status', [PipelineRunStatus::Pending->value, PipelineRunStatus::Running->value])
            ->exists();
    }
}
