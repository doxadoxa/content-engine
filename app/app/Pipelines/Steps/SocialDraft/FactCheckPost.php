<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Generation\FactCheck;
use App\Pipelines\Steps\Generation\FactCheckPayload;

/**
 * §10's non-optional step for a post.
 *
 * > **YMYL.** Для cryptoroute фактчек в `social_draft` — обязательный шаг, как в
 * > генерации. Неверное число в посте расходится быстрее, чем в статье.
 *
 * "Как в генерации" is meant literally, so this is shaped like {@see FactCheck}
 * down to the PASS convention and reuses its {@see FactCheckPayload}: an article
 * and a post disagreeing about what counts as unsupported would be worse than
 * either verdict alone, and it is the same business facts being checked against.
 *
 * **Only the chosen candidate is checked, not the pool.** The seven that lost do
 * not exist and will never be seen by anybody, so checking them would be eight
 * expensive calls to protect a reader from seven posts that are not going to be
 * published.
 *
 * **It runs beside the guard rather than inside it.** §4.3 requires
 * `guard_policy` to be model-free; this costs a model call, so it is its own
 * node and its own line in §8's report. {@see SaveDraft} is where the two verdicts
 * meet, and where a failed check on a YMYL project turns into an empty slot.
 *
 * On every other project it skips. §10 names one project, and a check nobody
 * needs is money spent to widen the pipeline's critical path.
 */
class FactCheckPost extends AbstractStep
{
    public static function key(): string
    {
        return 'fact_check_post';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [RankCandidates::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(RankCandidates::key())) {
            return StepResult::skip('Nothing was ranked for this slot, so there is nothing to check.');
        }

        $chosen = $context->output(RankCandidates::key(), ChosenCandidatePayload::class);

        if (! $chosen->wasChosen() || $chosen->candidate === null) {
            return StepResult::skip('No candidate cleared the week\'s selection bar, so there is nothing to check.');
        }

        if (! $context->project->is_ymyl) {
            // Skipped, not passed. {@see SaveDraft} reads this through
            // hasOutput() and treats an absent verdict as "not checked", which
            // is the only honest reading — see StepResult::skip().
            return StepResult::skip('This project is not YMYL, so §10 does not require a post fact-check.');
        }

        $facts = $context->project->original_data;

        $answer = $context->ask(
            role: 'factcheck',
            prompt: implode("\n\n", [
                $facts === []
                    ? 'No business facts were supplied, so any specific price, figure or date in the post is unsupported.'
                    : "The only facts about this business that are true:\n".json_encode($facts),
                "The post:\n".$chosen->candidate->text(),
                'List every claim that is unsupported or contradicts the facts above, one per line. '
                    .'If there are none, reply with exactly: PASS',
            ]),
            instructions: 'You are a fact-checker. You are looking for claims that cannot be supported.',
        );

        $findings = $this->parse($answer->text);

        return StepResult::success(new FactCheckPayload(
            passed: $findings === [],
            findings: $findings,
            required: true,
        ));
    }

    /**
     * The whole answer must be PASS, not merely contain it.
     *
     * The same rule and the same reason as {@see FactCheck} and its reply
     * sibling: a finding reading "this claim does not pass" was once read as a
     * clean bill of health, and on a YMYL project that is the difference
     * between holding a post back and telling somebody the wrong number about
     * their money. It matters more here than in an article, because §10's own
     * argument is that a wrong figure in a post travels faster.
     *
     * @return list<string>
     */
    private function parse(string $text): array
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return [];
        }

        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/', $trimmed) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        if (count($lines) === 1 && mb_strtoupper($lines[0]) === 'PASS') {
            return [];
        }

        $findings = [];

        foreach ($lines as $line) {
            $clean = trim(preg_replace('/^\s*[-*\d.)]+\s*/u', '', $line) ?? '');

            if ($clean !== '') {
                $findings[] = $clean;
            }
        }

        return $findings;
    }
}
