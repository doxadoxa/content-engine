<?php

declare(strict_types=1);

namespace App\Pipelines\Contracts;

/**
 * A pipeline is a key, a version and a set of steps. Nothing else.
 *
 * Order is not declared here — it is derived from what each step says it
 * depends on, so a definition cannot claim an order its dependencies
 * contradict. Adding a pipeline is a class plus one line in config/pipeline.php.
 */
interface PipelineDefinition
{
    public static function key(): string;

    /**
     * Bumped when the steps or their meaning change. Recorded on every run, so
     * "this run was produced by a pipeline that no longer exists in this shape"
     * stays answerable.
     */
    public static function version(): int;

    public static function name(): string;

    /**
     * The steps, in any order.
     *
     * @return list<class-string<Step>>
     */
    public function steps(): array;

    /**
     * Validation rules for the run input, applied before anything is dispatched
     * — a run that cannot succeed should not reach a queue.
     *
     * @return array<string, mixed>
     */
    public function inputRules(): array;
}
