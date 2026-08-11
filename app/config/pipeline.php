<?php

declare(strict_types=1);

use App\Pipelines\Definitions\ContentStudioPipeline;
use App\Pipelines\Definitions\DemoPipeline;
use App\Pipelines\Definitions\FeedbackPipeline;
use App\Pipelines\Definitions\GenerationPipeline;
use App\Pipelines\Definitions\PlanningPipeline;
use App\Pipelines\Definitions\RepurposePipeline;
use App\Pipelines\Definitions\ResearchPipeline;
use App\Pipelines\Definitions\SocialDraftPipeline;
use App\Pipelines\Definitions\SocialEngagePipeline;
use App\Pipelines\Definitions\SocialListenPipeline;
use App\Pipelines\Definitions\SocialPlanPipeline;
use App\Pipelines\Definitions\VisibilityPipeline;

return [

    /*
    |--------------------------------------------------------------------------
    | Registered pipelines
    |--------------------------------------------------------------------------
    |
    | Adding a pipeline is one line here plus a definition class. The engine
    | itself never changes — which is the point of phase 3: the pipelines of
    | phases 4 and 5 are descriptions of steps, not new machinery.
    |
    */

    'pipelines' => [
        DemoPipeline::class,
        ContentStudioPipeline::class,
        ResearchPipeline::class,
        PlanningPipeline::class,
        GenerationPipeline::class,
        RepurposePipeline::class,
        FeedbackPipeline::class,
        VisibilityPipeline::class,
        SocialListenPipeline::class,
        // Weekly, and deliberately not started by `engine:tick`: each contour
        // blocks only itself, and the week's slots feed none of the six the
        // tick runs. §4.3.
        SocialPlanPipeline::class,
        // One run per slot the week's plan placed, started when that slot is
        // due rather than by the tick. It is the one pipeline that spends the
        // expensive model deliberately — five to ten candidates so that one can
        // be published — which is also why §8 wants it as its own line. §4.3.
        SocialDraftPipeline::class,
        // §8 wants replies costed as their own line, and a line in that report
        // is a pipeline key. This one is started by the webhook rather than by
        // the scheduler — see the definition.
        SocialEngagePipeline::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Cheap steps (parsing, assembling, database work) and expensive ones (model
    | calls) run on separate queues with separate worker pools, so one long
    | generation cannot starve every quick step behind it (§3.2).
    |
    */

    'queues' => [
        'cheap' => env('PIPELINE_QUEUE_CHEAP', 'pipeline'),
        'expensive' => env('PIPELINE_QUEUE_EXPENSIVE', 'pipeline-expensive'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Step defaults
    |--------------------------------------------------------------------------
    |
    | Overridable per step. `backoff` is per attempt in seconds and its last
    | value repeats if a step allows more attempts than the list has entries.
    |
    */

    'defaults' => [
        'retries' => 3,
        'backoff' => [10, 60, 300],

        /*
         * A deadline per queue, because one number cannot serve both.
         *
         * A step's timeout has to sit under the timeout of the worker that runs
         * it, or the worker's signal arrives first and the step's own deadline
         * is unreachable — see PipelineTimeoutChainTest. The two pools are an
         * order of magnitude apart on purpose (config/horizon.php: "a cheap step
         * that has not finished in two minutes is wedged, while a model call
         * that takes four is working"), so a single default was always going to
         * be wrong for one of them. It was: 300 against a cheap worker that
         * stops at 120, which meant every step that did not override this was
         * killed rather than failed, with nothing recorded.
         *
         * `timeout` stays as the fallback for a queue not named here.
         */
        'timeouts' => [
            'pipeline' => 90,
            'pipeline-expensive' => 600,
        ],
        'timeout' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale claim grace
    |--------------------------------------------------------------------------
    |
    | How long past its timeout a step may sit in `running` before another
    | delivery is allowed to take it over. This is what turns "the worker was
    | killed mid-step" from a stuck run into a resumed one.
    |
    | Too short and a slow-but-alive step gets a second worker on top of it;
    | too long and a killed worker's run waits that long to move. It is a grace
    | period on top of the step's own timeout, not instead of it.
    |
    */

    'stale_claim_grace' => (int) env('PIPELINE_STALE_CLAIM_GRACE', 60),

    /*
    |--------------------------------------------------------------------------
    | When a run has stopped moving
    |--------------------------------------------------------------------------
    |
    | Two thresholds, because "worth a second look" and "not work in progress
    | any more" are different questions and the answers must not cross.
    |
    | `stall_after` is when `pipeline:reap` picks a run up. It is only a cheap
    | candidate filter — the reaper hands each one to
    | {@see \App\Pipelines\Core\PipelineRunner::resume()}, which decides what to
    | actually do against each step's own timeout. So it is safe for this to be
    | shorter than the longest step: a sweep legitimately half an hour into
    | `ask_assistants` is picked up, examined and left alone.
    |
    | `abandon_after` is when the *rest of the application* stops counting the
    | run as work in flight: {@see \App\Console\Commands\EngineTickCommand}
    | stops waiting for it and the dashboard stops drawing it as live. It is
    | deliberately much longer, and the ordering is the whole point — the reaper
    | always gets a run first, so the tick never starts a second copy of work
    | that was one `dispatchReady` away from finishing. Two hours also clears
    | the longest step in the engine (`ask_assistants`, 1800s) plus its grace by
    | a wide margin, so a slow run is never mistaken for a dead one.
    |
    | What this costs when it is missing: a visibility run wedged on 2026-08-07
    | held `isBusy()` true for two days. No article was drafted, and the only
    | symptom was one line an hour saying the project was still working.
    |
    */

    'stall_after' => (int) env('PIPELINE_STALL_AFTER', 900),

    'abandon_after' => (int) env('PIPELINE_ABANDON_AFTER', 7200),

];
