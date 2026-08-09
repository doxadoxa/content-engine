<?php

declare(strict_types=1);

namespace App\Pipelines\Core;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * A session advisory lock around a step handler's complete side-effect window.
 *
 * Database claims protect the step row. This lock additionally prevents a
 * timed-out replacement from entering the handler while the original process
 * is still alive. PostgreSQL releases it automatically if that process dies.
 */
final class PipelineStepMutex
{
    /** @var array<string, true> */
    private static array $held = [];

    public function acquire(string $runId, string $stepKey): bool
    {
        $key = $this->key($runId, $stepKey);

        if (isset(self::$held[$key])) {
            return false;
        }

        $row = $this->connection()->selectOne(
            'select pg_try_advisory_lock(hashtextextended(?, 0)) as acquired',
            [$key],
        );

        $value = $row->acquired ?? false;
        $acquired = $value === true || $value === 1 || $value === '1' || $value === 't';

        if ($acquired) {
            self::$held[$key] = true;
        }

        return $acquired;
    }

    /**
     * Whether another live process is inside this step's side-effect window.
     *
     * The question a recovery path has to ask before it writes anybody off. A
     * queue that gives up on one delivery calls `failed()` on *that* delivery,
     * which is not necessarily the delivery doing the work: on 2026-08-08 a
     * second copy of an `illustrate_draft` job was received while the first was
     * ninety seconds into drawing four pictures, exceeded its attempt limit
     * before it ran, and failed the run out from under a step that then
     * succeeded. The step row's `attempt` guard cannot see that — both
     * deliveries are talking about attempt 1 — and this is the only thing in
     * the engine that can, because PostgreSQL ties the lock to the session and
     * drops it when the process holding it dies.
     *
     * False when *we* hold it: a worker whose job the queue has killed is
     * exactly who should record the failure, and it is still holding its own
     * lock at that moment.
     */
    public function heldElsewhere(string $runId, string $stepKey): bool
    {
        if (isset(self::$held[$this->key($runId, $stepKey)])) {
            return false;
        }

        if (! $this->acquire($runId, $stepKey)) {
            return true;
        }

        // Asking is not claiming. Whatever runs next has to be able to take
        // this step, including the failure path that is about to.
        $this->release($runId, $stepKey);

        return false;
    }

    public function release(string $runId, string $stepKey): void
    {
        $key = $this->key($runId, $stepKey);

        if (! isset(self::$held[$key])) {
            return;
        }

        try {
            $this->connection()->selectOne(
                'select pg_advisory_unlock(hashtextextended(?, 0)) as released',
                [$key],
            );
        } finally {
            unset(self::$held[$key]);
        }
    }

    private function key(string $runId, string $stepKey): string
    {
        return "pipeline-step:{$runId}:{$stepKey}";
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}
