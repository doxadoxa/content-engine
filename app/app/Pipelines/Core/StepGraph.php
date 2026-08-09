<?php

declare(strict_types=1);

namespace App\Pipelines\Core;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Exceptions\InvalidPipelineDefinition;

/**
 * The dependency graph of one pipeline (§3.2).
 *
 * Built once when a run starts and again whenever a worker needs to know what
 * is ready. It is derived entirely from what each step declares, so there is no
 * second place where order is written down and no way for the two to disagree.
 *
 * Validation happens here rather than at dispatch time because a cycle or a
 * dangling dependency is a programming error in the definition — it is the same
 * error on every run, and finding it when the first run is created beats
 * finding it when the fourth step of the tenth run has nothing to wait for.
 */
final class StepGraph
{
    /**
     * @param  array<string, Step>  $steps  keyed by step key
     * @param  list<string>  $order  a topological order
     */
    private function __construct(
        private readonly array $steps,
        private readonly array $order,
    ) {}

    public static function for(PipelineDefinition $definition): self
    {
        $steps = [];

        foreach ($definition->steps() as $class) {
            $step = app($class);

            if (! $step instanceof Step) {
                throw new InvalidPipelineDefinition(
                    "`{$class}` is listed in pipeline `".$definition::key().'` but is not a Step.'
                );
            }

            $key = $class::key();

            if (isset($steps[$key])) {
                throw new InvalidPipelineDefinition(
                    'Two steps of pipeline `'.$definition::key()."` share the key `{$key}`."
                );
            }

            $steps[$key] = $step;
        }

        if ($steps === []) {
            throw new InvalidPipelineDefinition('Pipeline `'.$definition::key().'` has no steps.');
        }

        return new self($steps, self::sort($definition::key(), $steps));
    }

    /** @return list<string> */
    public function order(): array
    {
        return $this->order;
    }

    public function has(string $key): bool
    {
        return isset($this->steps[$key]);
    }

    public function step(string $key): Step
    {
        return $this->steps[$key]
            ?? throw new InvalidPipelineDefinition("No step `{$key}` in this pipeline.");
    }

    /** @return array<string, Step> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function positionOf(string $key): int
    {
        $position = array_search($key, $this->order, true);

        return $position === false ? 0 : $position;
    }

    /**
     * The step keys `$key` waits for.
     *
     * @return list<string>
     */
    public function dependenciesOf(string $key): array
    {
        return array_values(array_unique($this->step($key)->dependsOn()));
    }

    /**
     * Everything `$key` transitively waits for.
     *
     * Ordering is constrained by the direct edges; *visibility* reaches
     * further. A step three deep may legitimately read what the root produced —
     * the generation pipeline's draft step needs the compiled brief — and
     * making it re-declare that as a dependency would add an edge asserting an
     * ordering the graph already guarantees, purely to get at a payload.
     *
     * @return list<string>
     */
    public function ancestorsOf(string $key): array
    {
        $seen = [];
        $queue = $this->dependenciesOf($key);

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            foreach ($this->dependenciesOf($current) as $parent) {
                $queue[] = $parent;
            }
        }

        return array_keys($seen);
    }

    /**
     * Which of `$pending` may start, given what has settled.
     *
     * This is the whole of the parallelism: everything whose dependencies are
     * done comes back together, and the runner dispatches all of it. Two steps
     * that depend on the same predecessor and on nothing else are returned in
     * the same call and run at once, without either one knowing the other
     * exists.
     *
     * @param  list<string>  $pending
     * @param  list<string>  $settled
     * @return list<string>
     */
    public function ready(array $pending, array $settled): array
    {
        $settled = array_flip($settled);

        return array_values(array_filter($pending, function (string $key) use ($settled): bool {
            foreach ($this->dependenciesOf($key) as $dependency) {
                if (! isset($settled[$dependency])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Kahn's algorithm. Its leftovers are exactly the steps involved in a
     * cycle, which is the only useful thing to say about a cycle.
     *
     * @param  array<string, Step>  $steps
     * @return list<string>
     */
    private static function sort(string $pipeline, array $steps): array
    {
        $pending = [];

        foreach ($steps as $key => $step) {
            foreach ($step->dependsOn() as $dependency) {
                if (! isset($steps[$dependency])) {
                    throw new InvalidPipelineDefinition(
                        "Step `{$key}` of pipeline `{$pipeline}` depends on `{$dependency}`, "
                        .'which is not a step of that pipeline.'
                    );
                }
            }

            $pending[$key] = array_values(array_unique($step->dependsOn()));
        }

        $order = [];

        while ($pending !== []) {
            // Sorted, so a pipeline's steps come out in the same order on every
            // machine. Position is stored on the row and read by humans; it
            // should not depend on hash iteration order.
            $ready = array_keys(array_filter($pending, static fn (array $deps): bool => $deps === []));
            sort($ready);

            if ($ready === []) {
                $cycle = array_keys($pending);
                sort($cycle);

                throw new InvalidPipelineDefinition(
                    "The steps of pipeline `{$pipeline}` depend on each other in a cycle: "
                    .implode(' → ', $cycle).'.'
                );
            }

            foreach ($ready as $key) {
                $order[] = $key;
                unset($pending[$key]);
            }

            foreach ($pending as $key => $deps) {
                $pending[$key] = array_values(array_diff($deps, $ready));
            }
        }

        return $order;
    }
}
