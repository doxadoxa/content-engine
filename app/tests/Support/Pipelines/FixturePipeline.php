<?php

declare(strict_types=1);

namespace Tests\Support\Pipelines;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;

/**
 * A definition assembled from whichever fixture steps a test names, so the
 * graph tests can describe an arrangement without a class per arrangement.
 */
final class FixturePipeline implements PipelineDefinition
{
    /** @var list<class-string<Step>> */
    private array $steps = [];

    public static function key(): string
    {
        return 'fixture';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Fixture';
    }

    /**
     * @param  list<class-string<Step>>  $steps
     */
    public static function of(array $steps): self
    {
        $pipeline = new self;
        $pipeline->steps = $steps;

        return $pipeline;
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return [];
    }
}
