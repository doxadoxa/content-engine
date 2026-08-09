<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialEngage;

use App\Enums\InteractionState;
use App\Models\BrandBrief;
use App\Models\Interaction;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Social\ReplyGuard;
use App\Social\ReplyGuardFinding;
use App\Support\Content\ConcurrentStateChange;
use Illuminate\Support\Facades\Log;

/**
 * Check the draft, write it down, and stop (§4.2, §7).
 *
 * The last step of `social_engage`, and the end of every automated path in this
 * contour. It calls {@see Interaction::markDrafted()} and returns. There is no
 * branch below this one, no flag that turns into a send, and no band that
 * reaches one: §4.2 forbids autopublish here permanently — "не «пока не
 * заслужит»" — so the only thing that can put a reply into a thread is a person
 * pressing a button on the duty screen.
 *
 * **A failed guard is still written.** §7 requires the engine to say what it did
 * not do and why, so a draft that is too long, that names a forbidden topic, or
 * that failed §10's fact-check is stored with its findings and appears on the
 * duty screen marked as such. Dropping it would leave a conversation looking
 * like one the engine never reached, which is the state §7 calls indistinguishable
 * from broken.
 *
 * **It yields to the operator, always.** Two ways, both of which happen in
 * practice: the state is re-read here because a human can answer a conversation
 * in the twenty seconds a model call takes, and
 * {@see Interaction::transitionTo()} is compare-and-set underneath, so even a
 * read that was fresh a millisecond ago cannot overwrite an answer. Either way
 * the step skips rather than fails — the human winning a race is the contour
 * working.
 */
class GuardReply extends AbstractStep
{
    public function __construct(private readonly ReplyGuard $guard) {}

    public static function key(): string
    {
        return 'guard_reply';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        // The fact-check, not the draft: it depends on the draft itself, and
        // StepGraph hands a step every ancestor's output rather than only its
        // parents'. Naming both would add an edge that constrains nothing.
        return [FactCheckReply::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $conversation = $context->output(LoadConversation::key(), ConversationPayload::class);
        $draft = $context->output(DraftReply::key(), ReplyDraftPayload::class);

        $interaction = Interaction::query()->find($conversation->interactionId);

        if ($interaction === null) {
            return StepResult::skip('The conversation is gone; there is nobody left to answer.');
        }

        if ($interaction->state !== InteractionState::New) {
            return StepResult::skip(
                "An operator moved this conversation to {$interaction->state->value} while the reply was "
                .'being written, so the draft was not stored over it.'
            );
        }

        $verdict = $this->guard
            ->check($draft->text, $context->project, BrandBrief::activeFor($context->project))
            ->with($this->factcheckFinding($context));

        $interaction->draft_guard = $verdict->toArray();

        try {
            $interaction->markDrafted($draft->text);
        } catch (ConcurrentStateChange $e) {
            // The operator won the race between the read above and this write.
            // Their answer stands and the draft is dropped — which is exactly
            // what ConcurrentStateChange was written to make possible.
            Log::info('A reply draft was discarded because the conversation had already been answered', [
                'interaction' => $interaction->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return StepResult::skip('The conversation was answered while this reply was being written.');
        }

        return StepResult::success(new GuardedReplyPayload(
            interactionId: $interaction->getKey(),
            sendable: $verdict->sendable(),
            findings: array_map(
                static fn (ReplyGuardFinding $finding): string => $finding->detail,
                $verdict->findings,
            ),
        ));
    }

    /**
     * §10's verdict as a guard finding, or null.
     *
     * Blocking only when the project is YMYL, which is what `required` on the
     * payload records. On any other project the check does not run at all and
     * there is nothing here to carry.
     */
    private function factcheckFinding(StepContext $context): ?ReplyGuardFinding
    {
        if (! $context->hasOutput(FactCheckReply::key())) {
            return null;
        }

        $factcheck = $context->output(FactCheckReply::key(), ReplyFactCheckPayload::class);

        return $this->guard->factcheck($factcheck->findings, $factcheck->required);
    }
}
