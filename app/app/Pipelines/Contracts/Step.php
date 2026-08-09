<?php

declare(strict_types=1);

namespace App\Pipelines\Contracts;

use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * One unit of work (§3.1).
 *
 * A step knows what it needs, what it produces and how to do it. It does not
 * know which pipeline it is in, which step runs next, whether it is a retry, or
 * that a queue exists — all of which are the runner's business. That is what
 * makes the same step reusable across the six pipelines of §4 and testable by
 * calling handle() with a context and nothing else running.
 *
 * Implementations are resolved from the container, so constructor injection
 * works as usual.
 */
interface Step
{
    /** Stable across renames of the class: it is a database key. */
    public static function key(): string;

    /**
     * Step keys that must be settled before this one may start. The runner
     * derives the DAG from these, so an empty list means "can start at once"
     * and independent branches run in parallel without anyone arranging it.
     *
     * @return list<string>
     */
    public function dependsOn(): array;

    public function handle(StepContext $context): StepResult;

    /** Attempts including the first. 1 means no retry. */
    public function retries(): int;

    /**
     * Per-attempt backoff in seconds. A list shorter than retries() repeats its
     * last value.
     *
     * @return list<int>
     */
    public function backoff(): array;

    /** Wall-clock budget for one attempt, in seconds. */
    public function timeout(): int;

    public function queue(): string;
}
