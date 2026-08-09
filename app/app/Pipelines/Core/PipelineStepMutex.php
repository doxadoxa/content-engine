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
