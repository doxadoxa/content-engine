<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\Demo\AssembleResult;
use App\Pipelines\Steps\Demo\CountWords;
use App\Pipelines\Steps\Demo\ReadBrief;
use App\Pipelines\Steps\Demo\SummariseTopic;

/**
 * The engine's smoke test, and exit criterion 1 of phase 3: four steps, two of
 * them parallel.
 *
 *          ┌─ summarise_topic ─┐        (expensive queue — calls a model)
 *  read_brief                  ├─ assemble_result
 *          └─ count_words ─────┘        (cheap queue — no model)
 *
 * It exists to be run first on a new machine: if `pipeline:run demo` completes,
 * the database, both queues, the tenant context and the model gateway are all
 * wired correctly. It touches every feature of the engine — dependencies,
 * fan-out, fan-in, typed payloads, metering — and nothing outside it.
 */
class DemoPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'demo';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Demo (engine smoke test)';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [
            ReadBrief::class,
            SummariseTopic::class,
            CountWords::class,
            AssembleResult::class,
        ];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return [
            'topic' => ['required', 'string', 'min:1', 'max:500'],
            // Test hook: name a step key and that step throws when it starts.
            // This is how the retry and failure paths are exercised end to end
            // rather than by calling the runner's internals.
            'fail_at' => ['sometimes', 'string'],
            'fail_with' => ['sometimes', 'string', 'in:retryable,terminal'],
        ];
    }
}
