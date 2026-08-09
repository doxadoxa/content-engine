<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Support\Content\Squish;

/**
 * Measure entity coverage against the text (§5.3).
 *
 * §5.3 asks for coverage that is "проверяемое, а не декларативное" — so this
 * looks in the body rather than asking the model that wrote it whether it
 * covered everything. It costs nothing and it cannot flatter itself, which is
 * the whole difference between a checked claim and a declared one.
 *
 * On the cheap queue, and it runs beside the fact-check rather than after it:
 * neither reads the other.
 */
class CoverEntities extends AbstractStep
{
    use ResolvesUnit;

    public static function key(): string
    {
        return 'cover_entities';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [WriteDraft::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $unit = $this->unit($context);
        $draft = $context->output(WriteDraft::key(), DraftPayload::class);

        $haystack = Squish::text(mb_strtolower($draft->markdown));

        $coverage = [];

        foreach ($unit->entities as $entity) {
            $needle = Squish::text(mb_strtolower($entity));

            $coverage[$entity] = $needle !== '' && str_contains($haystack, $needle);
        }

        return StepResult::success(new EntityCoveragePayload($coverage));
    }
}
