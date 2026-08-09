<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Content\HouseStyle;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;

/**
 * Section headings before prose (§5.1).
 *
 * A separate step, and on the expensive model: §9 puts the good model on the
 * outline and the fact-check because those are where being wrong is structural
 * — a bad outline produces a well-written article about the wrong thing, and no
 * amount of drafting quality recovers it.
 */
class WriteOutline extends AbstractStep
{
    use ResolvesUnit;

    public function __construct(private readonly HouseStyle $style) {}

    public static function key(): string
    {
        return 'write_outline';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [CompileBrief::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $unit = $this->unit($context);
        $brief = $context->output(CompileBrief::key(), BriefContextPayload::class);

        $answer = $context->ask(
            role: 'outline',
            prompt: implode("\n\n", array_values(array_filter([
                "Target query: {$brief->targetQuery}",
                "Working title: {$unit->title}",
                'Unit type: '.$unit->type->label(),
                'Language: '.$brief->locale,
                $brief->originalData === []
                    ? null
                    : "Business facts you may use, and must not contradict:\n".json_encode($brief->originalData),
                'One of the sections must name real limitations — where this does not fit, '
                .'who it is wrong for. Not last, and not called "Limitations": give it a '
                .'declarative heading like "Where a cleaning service is the wrong call".',
                'Headings are declarative and specific. Never "Benefits", "Key considerations" '
                .'or "Conclusion".',
                'Return one heading per line and nothing else. No numbering, no markdown.',
                // Entities are what the article must actually name, and nothing
                // was setting them: `entities` came off the keyword source,
                // which never populates it, so every article scored 0 of 0 on
                // coverage and the GEO layer had nothing to check itself
                // against.
                'Then a line `ENTITIES:` followed by 5 to 10 specific things this article '
                .'must name to be complete — materials, places, standards, product types, '
                .'processes — separated by " | ". Not the target query, and not generic '
                .'words like "quality" or "service".',
            ]))),
            instructions: implode("\n\n", [
                $brief->compiledBrief,
                'You are outlining an article. Headings only.',
                $this->style->instructions($brief->locale),
            ]),
        );

        $sections = $this->parse($answer->text);
        $entities = $this->entities($answer->text);

        if ($entities !== []) {
            $unit->forceFill(['entities' => $entities])->save();
        }

        if (count($sections) < 2) {
            // Terminal: an outline of one line is a refusal or a malformed
            // answer, and asking the same model the same thing again produces
            // the same non-answer more expensively.
            throw new TerminalStepFailure(
                'The model returned no usable outline for `'.$brief->targetQuery.'`.'
            );
        }

        return StepResult::success(new OutlinePayload($sections));
    }

    /**
     * The `ENTITIES:` line, if the model gave one.
     *
     * @return list<string>
     */
    private function entities(string $text): array
    {
        if (preg_match('/^ENTITIES:\s*(.+)$/mu', $text, $matches) !== 1) {
            return [];
        }

        $entities = array_map(trim(...), explode('|', $matches[1]));

        return array_values(array_filter(
            $entities,
            static fn (string $entity): bool => $entity !== '' && mb_strlen($entity) < 60,
        ));
    }

    /** @return list<string> */
    private function parse(string $text): array
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];

        $sections = [];

        foreach ($lines as $line) {
            // The entities line is an answer to a different question and is
            // read by entities(); it is not a section.
            if (preg_match('/^ENTITIES:/u', trim($line)) === 1) {
                continue;
            }

            // Models add "## ", "1. " and "- " however firmly they are asked
            // not to. Stripping is cheaper than another round trip.
            $clean = trim(preg_replace('/^\s*(#{1,6}|[-*\d.)]+)\s*/u', '', $line) ?? '');

            if ($clean !== '') {
                $sections[] = $clean;
            }
        }

        return $sections;
    }
}
