<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Console\Commands\EngineTickCommand;
use App\Console\Commands\SocialListenCommand;
use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\SocialPlan\GatherCandidates;
use App\Pipelines\Steps\SocialPlan\PlaceSlots;
use App\Pipelines\Steps\SocialPlan\ReadGovernor;
use App\Pipelines\Steps\SocialPlan\RecordPlan;

/**
 * The week's slots, weekly (§4.3).
 *
 *   read_governor     ──┐
 *                       ├─ place_slots → record_plan
 *   gather_candidates ──┘
 *
 * > social_plan (еженедельно)
 * >   signals + сезонность + пробелы корпуса → слоты недели
 *
 * The fan-in is the argument. How many posts the week may hold is a fact about
 * the account's history and has nothing to do with what there is to say; what
 * there is to say is a fact about the pool and has nothing to do with the
 * ceiling. They are computed independently and meet exactly once, in the step
 * that has to reconcile them — which is also the only step that writes.
 *
 * **The empty plan is the normal outcome and the important one.** §4.3: "Пустой
 * слот — валидный результат пайплайна, и в этом всё отличие от генератора."
 * Every step here can succeed having produced nothing, and `record_plan` exists
 * so that producing nothing is still visible: §7 makes the explanation
 * mandatory, because a machine that quietly does nothing cannot be told from a
 * broken one.
 *
 * **No model is called anywhere in it.** §4.3 requires the deterministic guard
 * to stay deterministic, and everything this pipeline does — count the week,
 * divide replies by impressions, compare against a bar, test a window for
 * containment — is arithmetic. The expensive model call belongs to
 * `social_draft`, which §4.3 calls the one place where an expensive model is
 * spent deliberately, and which only ever runs for a slot this pipeline decided
 * was worth having.
 *
 * **Its own schedule entry, not a branch of the tick.** The rule
 * {@see EngineTickCommand::isBusy()} states is that each contour blocks only
 * itself, and this contour feeds none of the six the tick runs: a project
 * halfway through writing an article has exactly as much reason to plan its
 * week as one that is idle. The mirror is also true — a weekly planning run
 * that took thirty seconds must not stop an article being drafted for an hour.
 * `routes/console.php` gives it a weekly line, and the command guards against
 * stacking two planning runs on one project the way
 * {@see SocialListenCommand::isListening()} does.
 */
class SocialPlanPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'social_plan';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Social plan (signals + seasonality + gaps → the week\'s slots)';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [
            ReadGovernor::class,
            GatherCandidates::class,
            PlaceSlots::class,
            RecordPlan::class,
        ];
    }

    /**
     * Nothing required. A weekly schedule has nobody to name a week, and the
     * week is always the one the run happens in — planning further ahead would
     * mean placing slots against duty hours and a reply rate that will both
     * have moved by the time they arrive.
     *
     * @return array<string, mixed>
     */
    public function inputRules(): array
    {
        return [];
    }
}
