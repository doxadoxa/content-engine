<?php

declare(strict_types=1);

namespace App\Social\Jobs;

use App\Console\Commands\EngineTickCommand;
use App\Enums\InteractionState;
use App\Models\Interaction;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\SocialEngagePipeline;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Start `social_engage` for one incoming conversation (§4.2).
 *
 * Thin, and thin on purpose: everything it does is decide whether the
 * conversation still wants a reply and then hand it to
 * {@see SocialEngagePipeline}. The work, the money and the durability live in
 * the run — see that definition for why a reply is a pipeline rather than a job
 * that calls a model, which comes down to §8 wanting replies costed as their
 * own line and metering in this engine meaning `pipeline_steps` rows.
 *
 * What stays here is the one thing a pipeline cannot do for itself: being
 * dispatched by the event rather than by the clock.
 *
 * **Why the receiver dispatches this directly instead of leaving it to the
 * hourly tick.** {@see EngineTickCommand::isBusy()} refuses to start anything
 * while any pipeline run is pending or running — a sensible rule for a
 * generation run that takes minutes and costs money, and the wrong rule
 * entirely here. §4.2 measures this contour in minutes because the algorithm
 * weighs the speed of the first hour, so a reply queued behind an article
 * generation is a reply that misses the window it was worth writing for. The
 * event arrives, the row is written, the drafting is dispatched, and none of it
 * waits for the engine to be idle.
 *
 * **And why the receiver does not draft inline.** Meta retries anything slow or
 * non-2xx, so a model call inside the request turns one event into duplicate
 * deliveries. The endpoint persists and returns; this is where the time goes.
 *
 * Nothing this job ever does will send a reply. §4.2 forbids autopublish in
 * this contour permanently — "не «пока не заслужит», а никогда" — so the end of
 * the drafting path is {@see Interaction::markDrafted()} and a human.
 */
class DraftInteractionReplyJob implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt, as with the other jobs in the engine: the retry policy is a
     * decision of the contour rather than of the queue.
     */
    public int $tries = 1;

    public function __construct(public string $interactionId) {}

    public function handle(CurrentProject $current, PipelineRunner $runner): void
    {
        $interaction = Interaction::acrossProjects()->find($this->interactionId);

        if ($interaction === null) {
            return;
        }

        // Under the conversation's own tenant. Every read the drafting pipeline
        // will make is scoped and fails closed, and a queued job arrives with
        // no project in context.
        $current->run($interaction->project_id, function () use ($current, $interaction, $runner): void {
            if ($interaction->state !== InteractionState::New) {
                // An operator answered or ignored it while this waited. §4.2's
                // whole point is that the human wins.
                return;
            }

            $project = $current->get();

            if ($project === null) {
                // Unreachable through run(), which resolves the project before
                // the closure. Stated rather than assumed because the runner
                // needs the model and a null here would be a fatal in a queue
                // worker instead of a line in the log.
                Log::warning('A reply draft was skipped because its project could not be resolved', [
                    'interaction' => $interaction->getKey(),
                ]);

                return;
            }

            // Straight into the run, not through `engine:tick`. The run is
            // created and its first step queued inside this call, so the whole
            // of §4.2's latency from here on is the model.
            $runner->start(SocialEngagePipeline::key(), $project, [
                'interaction_id' => $interaction->getKey(),
            ]);
        });
    }
}
