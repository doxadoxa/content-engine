<?php

declare(strict_types=1);

namespace App\Pipelines\Core;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Exceptions\InvalidPipelineDefinition;

/**
 * The pipelines this build knows about, by key.
 *
 * Keyed lookup rather than a class name on the run row, so a run outlives a
 * class rename and a pipeline can be found by the string an operator typed.
 */
class PipelineRegistry
{
    /** @var array<string, PipelineDefinition>|null */
    private ?array $definitions = null;

    public function get(string $key): PipelineDefinition
    {
        return $this->all()[$key] ?? throw new InvalidPipelineDefinition(
            "No pipeline `{$key}` is registered. Known: ".implode(', ', array_keys($this->all())).'.'
        );
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /** @return array<string, PipelineDefinition> */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];

        /** @var list<class-string<PipelineDefinition>> $classes */
        $classes = config('pipeline.pipelines', []);

        foreach ($classes as $class) {
            $definition = app($class);

            if (! $definition instanceof PipelineDefinition) {
                throw new InvalidPipelineDefinition(
                    "`{$class}` is registered in config/pipeline.php but is not a PipelineDefinition."
                );
            }

            $definitions[$class::key()] = $definition;
        }

        return $this->definitions = $definitions;
    }

    public function graph(string $key): StepGraph
    {
        return StepGraph::for($this->get($key));
    }
}
