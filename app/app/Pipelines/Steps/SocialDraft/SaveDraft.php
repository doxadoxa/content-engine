<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Models\SocialPlan;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Generation\CompileBrief;
use App\Pipelines\Steps\Generation\FactCheckPayload;
use App\Pipelines\Steps\Generation\ResolvesUnit;
use App\Pipelines\Steps\SocialPlan\RecordPlan;
use App\Publishing\ThreadsPublisher;
use App\Support\Social\ChannelPayload;
use App\Support\Social\ChannelPayloadSegment;
use Illuminate\Support\Str;

/**
 * The fan-in, and the only step here that writes (§3, §4.3, §7).
 *
 * Two outcomes and they are both successes. Either the slot gets a
 * {@see ChannelPayload} and the unit reaches `draft`, or the slot stays empty
 * and the reason lands where §7 already looks. §4.3 says the empty slot is what
 * separates this pipeline from a generator, so the branch that produces nothing
 * is the ordinary one rather than the error path.
 *
 * **One writer, because there are three ways to refuse.** The bar
 * ({@see RankCandidates}), the deterministic guard ({@see GuardPolicy}) and
 * §10's fact-check ({@see FactCheckPost}) can each stop a post, and every one of
 * them ends here rather than writing its own refusal. That is what makes §7's
 * summary one query: one code, one row, one place to look, whichever rule fired.
 *
 * **It does not publish, and there is no branch that could.** The unit is left
 * in `draft` for the delivery of §9 and the operator of §7. This is the
 * difference between the ceiling being architecture and the ceiling being
 * discipline (§10): the governor cannot be argued with by a step that has no
 * `markPublished()` in it.
 *
 * **Never the least-bad candidate.** There is no fallback below — no second
 * choice from the pool, no relaxation of the bar, no "publish it and flag it".
 * §4.3 spends a paragraph switching off the calendar-filling instinct, and this
 * is the step where it would otherwise switch itself back on.
 */
class SaveDraft extends AbstractStep
{
    use ResolvesUnit;

    /**
     * The code §7's summary groups the week's refusals by.
     *
     * The same one {@see RecordPlan} writes for
     * a slot that was never placed, because they are the same question — "чего
     * движок делать не стал и почему" — asked at two moments. A second code
     * would split one answer across two buckets.
     */
    public const string EMPTY_SLOT = 'empty_slot';

    /**
     * Who may reply, in §3's payload.
     *
     * `everyone`, and not configurable. §1's second fact is that replies are
     * half the value of the channel and that the accounts which grow are the
     * ones replying most; a post that restricts who may answer is the one shape
     * that argues with the reason the channel exists.
     */
    private const string REPLY_CONTROL = 'everyone';

    public static function key(): string
    {
        return 'save_draft';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        // The three branches of §4.3's graph, fanned in. A skipped branch
        // releases this one as if it had succeeded, which is why every read
        // below is guarded by hasOutput().
        return [GuardPolicy::key(), GenerateImage::key(), FactCheckPost::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(RankCandidates::key())) {
            // The chain skipped before anything was ranked, which today means
            // one thing: the slot's window had closed before the run reached it
            // (§5). `social:kill-expired` is what records that and removes the
            // unit, so writing a second explanation here would double it.
            return StepResult::skip('Nothing was ranked for this slot, so there is nothing to save.');
        }

        $unit = $this->unit($context);
        $slot = $context->output(LoadContext::key(), SlotContextPayload::class);
        $chosen = $context->output(RankCandidates::key(), ChosenCandidatePayload::class);

        $reason = $this->refusal($context, $chosen);

        if ($reason !== null) {
            return $this->leaveEmpty($context, $unit, $slot, $reason);
        }

        // Non-null by the guard above: `refusal()` returns a sentence for every
        // path in which there is no candidate.
        $candidate = $chosen->candidate;
        $guard = $context->output(GuardPolicy::key(), GuardVerdictPayload::class);
        $assetId = $context->hasOutput(GenerateImage::key())
            ? $context->output(GenerateImage::key(), PostImagePayload::class)->assetId
            : null;

        $payload = new ChannelPayload(
            segments: $this->segments($candidate, $assetId),
            linkAttachment: $candidate?->link,
            replyControl: self::REPLY_CONTROL,
            angle: $candidate?->angle,
        );

        $unit->forceFill([
            'channel_type' => ChannelType::Threads->value,
            // The canonical shape of §3, and the delivery journal of §9. Written
            // through toArray() rather than assembled inline so the column and
            // {@see ThreadsPublisher} cannot disagree about what a segment is.
            'channel_payload' => $payload->toArray(),
            // The text as prose as well as as a payload. The payload is what the
            // adapter sends; this is what a preview, a search and the corpus
            // read, and every other unit in the engine has it.
            'body_markdown' => $candidate?->text(),
            'summary' => Str::limit((string) $candidate?->text(), 200),
            // Resolved by the guard on its way through (§4.3). §1.3 asks a post
            // and an article to be "два ребёнка одного кластера сущностей", and
            // this column is what makes that true of the corpus rather than only
            // of the prompt.
            'entities' => $slot->isDerivative() ? $slot->parentEntities : $guard->entities,
            'factcheck' => $this->factcheck($context)?->toArray() ?? [],
        ])->save();

        $this->reachDraft($unit);

        $context->remember('social_draft.unit_id', (string) $unit->getKey());
        $context->remember('social_draft.segments', count($payload->segments));

        if ($candidate !== null && $candidate->isChain()) {
            // §2 makes a chain "исключение, которое шаг обязан обосновать", and
            // an obligation nobody can audit afterwards is not one. The guard
            // refuses a chain without a reason; this is where the reason of the
            // chain that *was* allowed ends up, on the run, next to the segment
            // count it explains.
            $context->remember('social_draft.chain_reason', $candidate->chainReason);
        }

        return StepResult::success(new SavedDraftPayload(
            unitId: (string) $unit->getKey(),
            saved: true,
            segments: count($payload->segments),
            assetId: $assetId,
        ));
    }

    /**
     * Why this slot may not be filled, or null if it may.
     *
     * The order is the order the refusals happened in, which is also the order
     * an operator would want them: nothing was good enough, or the one that was
     * broke a rule, or it broke §10.
     */
    private function refusal(StepContext $context, ChosenCandidatePayload $chosen): ?string
    {
        if (! $chosen->wasChosen() || $chosen->candidate === null) {
            return $chosen->refusal ?? 'No candidate survived selection.';
        }

        if ($context->hasOutput(GuardPolicy::key())) {
            $guard = $context->output(GuardPolicy::key(), GuardVerdictPayload::class);

            if (! $guard->passed()) {
                return $guard->reason();
            }
        }

        $factcheck = $this->factcheck($context);

        if ($factcheck !== null && $factcheck->required && ! $factcheck->passed) {
            // §10 for a YMYL project, and an empty slot rather than a failed
            // run. The difference matters: a failed run is a machine that broke,
            // and this is a machine that decided — the same text would fail the
            // same check, and §7's operator needs to read that rather than a
            // stack trace.
            return sprintf(
                'The fact-check could not support %d claim(s) in the post and this project is YMYL, so '
                    .'§10 does not let it become a draft: %s',
                count($factcheck->findings),
                implode(' | ', array_slice($factcheck->findings, 0, 3)),
            );
        }

        return null;
    }

    /**
     * Leave the slot empty and put the reason where §7 will find it.
     *
     * On the week's `social_plans` row, next to the refusals the planner wrote
     * for the slots it never placed, because §7's summary is one screen and this
     * is the same line of it. A slot that was placed and then came to nothing is
     * exactly as interesting as one that was never placed.
     *
     * The unit is left exactly as it was — `idea`, no body, no payload. It keeps
     * its time, so the next run of this pipeline may still fill it if the pool
     * comes out better; and it keeps whatever image was drawn beside the guard,
     * which that run will find rather than pay for again.
     */
    private function leaveEmpty(
        StepContext $context,
        ContentItem $unit,
        SlotContextPayload $slot,
        string $reason,
    ): StepResult {
        $detail = $unit->title.' — '.$reason;

        $plan = SocialPlan::forWeek($context->project, $unit->slot_at)
            // No planning run has recorded this week yet — a slot drafted by
            // hand, or a week whose planner has not run. Recorded with what the
            // week actually holds rather than with a guess, so the row exists
            // to hang the reason on and still says something true about the
            // ceiling and the floor.
            ?? SocialPlan::record($context->project, $slot->verdict, $slot->verdict->taken());

        $plan->note(self::EMPTY_SLOT, $detail);

        $context->remember('social_draft.empty_reason', $reason);

        return StepResult::success(new SavedDraftPayload(
            unitId: (string) $unit->getKey(),
            saved: false,
            reason: $reason,
        ));
    }

    /**
     * The post as §3's segment list, with the picture on the first one.
     *
     * The image goes on the root and only on the root. §2 wants one post, one
     * thought, with a picture; a chain that illustrated every segment would be
     * three images of one idea, and the later segments of a chain are the
     * argument rather than the subject.
     *
     * @return list<ChannelPayloadSegment>
     */
    private function segments(?PostCandidate $candidate, ?string $assetId): array
    {
        $segments = [];

        foreach ($candidate === null ? [] : $candidate->segments as $position => $text) {
            $segments[] = new ChannelPayloadSegment(
                position: $position,
                text: $text,
                assetId: $position === 0 ? $assetId : null,
            );
        }

        return $segments;
    }

    /** §10's verdict, or null on a project that does not need one. */
    private function factcheck(StepContext $context): ?FactCheckPayload
    {
        if (! $context->hasOutput(FactCheckPost::key())) {
            return null;
        }

        return $context->output(FactCheckPost::key(), FactCheckPayload::class);
    }

    /**
     * Move the unit to `draft`, through the states rather than past them.
     *
     * `idea → queued → generating → draft` is the map, and this walks it, the
     * same way {@see CompileBrief} does for an
     * article. A unit already in `draft` is left alone: re-drafting a slot is an
     * ordinary thing to want, `draft → draft` is not an edge, and calling it
     * would fail the run on the last step after paying for eight candidates.
     */
    private function reachDraft(ContentItem $unit): void
    {
        if ($unit->state === ContentItemState::Idea) {
            $unit->markQueued();
        }

        if ($unit->state === ContentItemState::Queued) {
            $unit->markGenerating();
        }

        if ($unit->state === ContentItemState::Generating) {
            $unit->markDrafted();
        }
    }
}
