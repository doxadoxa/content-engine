<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialEngage;

use App\Models\Project;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Generation\FactCheck;

/**
 * §10's non-optional step, for a reply.
 *
 * > **YMYL.** Для cryptoroute фактчек в `social_draft` — обязательный шаг, как в
 * > генерации. Неверное число в посте расходится быстрее, чем в статье.
 *
 * A reply spreads faster still, and it is addressed to a person who asked. So
 * the same rule holds here: on a project whose {@see Project::$is_ymyl} is set,
 * a reply is checked against what the business has actually told us before it
 * is offered, and {@see GuardReply} turns a finding into a blocked one-tap
 * send. Everywhere else this step skips, because a check nobody needs is a
 * model call on the latency path §4.2 is judged on.
 *
 * Shaped like {@see FactCheck} deliberately, down to the PASS convention, so
 * that the two answer the same question the same way — a reply and an article
 * disagreeing about what counts as unsupported would be worse than either
 * verdict on its own.
 *
 * This step never fails the run for finding something. A fact-check that found
 * a problem is a fact-check working; failing here would lose the finding the
 * operator needs and leave the conversation undrafted.
 */
class FactCheckReply extends AbstractStep
{
    public static function key(): string
    {
        return 'fact_check_reply';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [DraftReply::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->project->is_ymyl) {
            // Skipped, not passed. GuardReply reads this through hasOutput()
            // and treats an absent verdict as "not checked", which is the only
            // honest reading — see StepResult::skip().
            return StepResult::skip('This project is not YMYL, so §10 does not require a reply fact-check.');
        }

        $draft = $context->output(DraftReply::key(), ReplyDraftPayload::class);
        $facts = $context->project->original_data;

        $answer = $context->ask(
            role: 'factcheck',
            prompt: implode("\n\n", [
                $facts === []
                    ? 'No business facts were supplied, so any specific price, figure or date in the reply is unsupported.'
                    : "The only facts about this business that are true:\n".json_encode($facts),
                "The reply:\n{$draft->text}",
                'List every claim that is unsupported or contradicts the facts above, one per line. '
                    .'If there are none, reply with exactly: PASS',
            ]),
            instructions: 'You are a fact-checker. You are looking for claims that cannot be supported.',
        );

        $findings = $this->parse($answer->text);

        return StepResult::success(new ReplyFactCheckPayload(
            passed: $findings === [],
            findings: $findings,
            required: true,
        ));
    }

    /**
     * The whole answer must be PASS, not merely contain it.
     *
     * The same rule and the same reason as {@see FactCheck::parse()}: a finding
     * reading "this claim does not pass" was read as a clean bill of health,
     * and on a YMYL project that is the difference between holding a reply back
     * and telling somebody the wrong number about their money.
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
