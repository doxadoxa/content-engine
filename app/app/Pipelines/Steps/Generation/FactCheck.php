<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * Check the draft against what we actually know (§5.2).
 *
 * On its own model, because §9 puts the expensive one here. The verdict is
 * recorded either way; what the project's YMYL flag changes is whether a
 * failed verdict is allowed to reach `draft` — the block itself lives in
 * {@see FinaliseDraft}, so that one place decides what happens to the unit.
 *
 * This step never fails the run for finding problems. A fact-check that found
 * something is a fact-check working, and a run that fails on it loses the
 * findings an operator needs to fix the article.
 */
class FactCheck extends AbstractStep
{
    use ResolvesUnit;

    public static function key(): string
    {
        return 'fact_check';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [WriteDraft::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $brief = $context->output(CompileBrief::key(), BriefContextPayload::class);
        $draft = $context->output(WriteDraft::key(), DraftPayload::class);

        $answer = $context->ask(
            role: 'factcheck',
            prompt: implode("\n\n", [
                $brief->originalData === []
                    ? 'No business facts were supplied, so any specific price, figure or date in the text is unsupported.'
                    : "The only facts about this business that are true:\n".json_encode($brief->originalData),
                "The article:\n".$draft->markdown,
                'List every claim that is unsupported or contradicts the facts above, one per line. '
                    .'If there are none, reply with exactly: PASS',
            ]),
            instructions: 'You are a fact-checker. You are looking for claims that cannot be supported.',
        );

        $findings = $this->parse($answer->text);

        return StepResult::success(new FactCheckPayload(
            passed: $findings === [],
            findings: $findings,
            required: $context->project->is_ymyl,
        ));
    }

    /** @return list<string> */
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

        // The *whole answer* must be PASS, not merely contain it. Searching for
        // the substring meant a finding that said "this claim does not pass"
        // was read as a clean bill of health — on a YMYL project that is the
        // difference between blocking a draft and publishing an unsupported
        // claim about somebody's money.
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
