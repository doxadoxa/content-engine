<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Social\Governor;
use App\Support\Social\PostFormat;

/**
 * Keep one, and only if the week would have it (§4.3, §2).
 *
 * Two rules, and the second is the one that gets forgotten.
 *
 * **The format rules of §2 are applied, not consulted.** {@see PostFormat}
 * turns the paragraph — a question works, a personal observation with a figure
 * works, a caption works; a thread-essay, an uninvited hot take, unframed
 * self-promotion and a bare link do not — into a number on the 0–100 scale the
 * rest of the engine already uses for weight. The highest wins. Nothing here
 * asks a model what it thinks of its own drafts: §4.3 spends the expensive
 * model in exactly one place and this is not it, and a bar that answers
 * differently on two identical runs is not a bar.
 *
 * **The week's bar is the governor's, and in a weak week it is higher.** §4.3's
 * ceiling row reads "trailing reply rate ниже порога → срезает частоту **и
 * поднимает планку отбора**", and {@see Governor} already implements both
 * halves — `selection_floor` 50 ordinarily, `throttled_selection_floor` 70 when
 * the trailing rate is weak. Obeying only the first half would make a bad week a
 * shorter version of a good one: the same candidates, fewer of them. Obeying
 * this one makes it a *pickier* week, which is the point — an account whose
 * history is telling the platform to show it to fewer people cannot afford the
 * merely adequate post.
 *
 * **Nothing that fails is rescued.** There is no branch below that lowers the
 * bar, that retries the pool, or that takes the best of a bad set. A pool where
 * the winner is under the bar leaves the slot empty with a sentence, which §4.3
 * calls a valid outcome and §7 calls mandatory.
 */
class RankCandidates extends AbstractStep
{
    public static function key(): string
    {
        return 'rank';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [DraftCandidates::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(DraftCandidates::key())) {
            return StepResult::skip('No pool was written for this slot, so there is nothing to rank.');
        }

        $slot = $context->output(LoadContext::key(), SlotContextPayload::class);
        $pool = $context->output(DraftCandidates::key(), CandidatePoolPayload::class);

        $bar = $slot->verdict->selectionFloor;

        $scores = array_map(
            static fn (PostCandidate $candidate): int => $candidate->score(),
            $pool->candidates,
        );

        $best = $this->best($pool->candidates, $scores);

        if ($best === null) {
            return StepResult::success(new ChosenCandidatePayload(
                candidate: null,
                score: 0,
                bar: $bar,
                throttled: $slot->verdict->throttled,
                scores: $scores,
                refusal: 'The pool came back empty: the model wrote nothing usable for this slot.',
            ));
        }

        [$candidate, $score] = $best;

        if ($score < $bar) {
            return StepResult::success(new ChosenCandidatePayload(
                candidate: null,
                score: $score,
                bar: $bar,
                throttled: $slot->verdict->throttled,
                scores: $scores,
                refusal: sprintf(
                    'The best of %d candidates scored %d against a selection bar of %d%s, so this slot '
                        .'stays empty rather than taking the least bad of them (§4.3).',
                    count($pool->candidates),
                    $score,
                    $bar,
                    $slot->verdict->throttled
                        ? ' — a bar raised because the trailing reply rate is weak (§4.3)'
                        : '',
                ),
            ));
        }

        $context->remember('social_draft.chosen_score', $score);
        $context->remember('social_draft.selection_bar', $bar);

        return StepResult::success(new ChosenCandidatePayload(
            candidate: $candidate,
            score: $score,
            bar: $bar,
            throttled: $slot->verdict->throttled,
            scores: $scores,
        ));
    }

    /**
     * The highest-scoring candidate, earliest first on a tie.
     *
     * The tie-break matters more than it looks. §2's angles are tried in a fixed
     * order and the question comes first, so a pool where a question and a
     * caption score the same keeps the question — which is the shape §2 names
     * first and the one that asks for the reply §1 measures the account on.
     *
     * @param  list<PostCandidate>  $candidates
     * @param  list<int>  $scores
     * @return array{PostCandidate, int}|null
     */
    private function best(array $candidates, array $scores): ?array
    {
        $bestIndex = null;

        foreach ($candidates as $index => $candidate) {
            if ($candidate->text() === '') {
                continue;
            }

            if ($bestIndex === null || $scores[$index] > $scores[$bestIndex]) {
                $bestIndex = $index;
            }
        }

        return $bestIndex === null ? null : [$candidates[$bestIndex], $scores[$bestIndex]];
    }
}
