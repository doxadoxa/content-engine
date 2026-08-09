<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Enums\ContentItemState;
use App\Enums\RejectionReason;
use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;

/**
 * The root: pin the brief version, gather the real data, take the unit (§5.1).
 *
 * Pinning matters more than it looks. The brief is versioned precisely so a
 * published article can say which voice it was written from (§2), and that only
 * holds if the id is recorded when the run starts — a run that read the active
 * brief in each step would attribute itself to whichever version was live when
 * its last step happened to execute.
 */
class CompileBrief extends AbstractStep
{
    use ResolvesUnit;

    public static function key(): string
    {
        return 'compile_brief';
    }

    public function handle(StepContext $context): StepResult
    {
        $unit = $this->unit($context);
        $brief = BrandBrief::activeFor($context->project);

        if ($brief === null) {
            // Terminal: generating without a brief is how an engine produces
            // text that sounds like nobody, which is the failure §3.1 of the
            // spec exists to prevent.
            throw new TerminalStepFailure(
                'This project has no active brand brief. Fill it in before generating.'
            );
        }

        $author = $this->author($context);

        if ($context->project->is_ymyl && $author === []) {
            // §1: real author names are a requirement on YMYL, not a nicety.
            throw new TerminalStepFailure(
                'A YMYL project must have at least one named author before it can generate.'
            );
        }

        // The unit is being worked on now. Through the state machine, which is
        // the only thing allowed to move it (§2).
        if ($unit->state === ContentItemState::Idea) {
            $unit->markQueued();
        }

        if ($unit->state === ContentItemState::Queued) {
            $unit->markGenerating();
        }

        $unit->forceFill(['brand_brief_id' => $brief->getKey()])->save();

        return StepResult::success(new BriefContextPayload(
            briefId: $brief->getKey(),
            compiledBrief: $this->withOutstandingReview($brief->compileToPrompt(), $unit),
            // Only when the planner asked for it (§4.3). Handing a model the
            // price list on every article is how prices end up in guides that
            // never needed them.
            originalData: $unit->needs_original_data ? $context->project->original_data : [],
            author: $author,
            targetQuery: (string) $unit->target_query,
            locale: $unit->locale,
        ));
    }

    /**
     * The brief, plus whatever a human said was wrong last time.
     *
     * A unit only reaches this step twice if somebody sent it back, and a
     * rewrite that cannot see the complaint is a rewrite that reproduces it —
     * the operator typed a reason into a closed set and a note into a box, and
     * without this the model never learns either. Both writing steps read
     * `compiledBrief`, so putting it here is what makes the outline *and* the
     * draft answer the same objection.
     *
     * Kept until the unit is approved rather than cleared when it is read: the
     * complaint stands until somebody accepts the work, so a second rewrite
     * before that still has to answer it.
     */
    private function withOutstandingReview(string $compiled, ContentItem $unit): string
    {
        $review = $unit->review;

        if ($unit->reviewed_at === null || $review === []) {
            return $compiled;
        }

        $reason = RejectionReason::tryFrom((string) ($review['reason'] ?? ''));
        $note = trim((string) ($review['note'] ?? ''));

        if ($reason === null && $note === '') {
            return $compiled;
        }

        return implode("\n\n", array_filter([
            $compiled,
            'This piece was written once and sent back. Fix what was wrong with it rather '
            .'than writing the same article again.',
            $reason === null ? null : 'What was wrong: '.$reason->label().'.',
            $note === '' ? null : 'What the reviewer asked for: '.$note,
        ]));
    }

    /** @return array<string, mixed> */
    private function author(StepContext $context): array
    {
        $authors = $context->project->authors;

        return $authors === [] ? [] : $authors[0];
    }
}
