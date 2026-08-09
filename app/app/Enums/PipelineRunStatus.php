<?php

declare(strict_types=1);

namespace App\Enums;

enum PipelineRunStatus: string
{
    /** Rows exist, nothing dispatched yet. */
    case Pending = 'pending';

    case Running = 'running';

    /** A step failed with nothing left to try. `failed_step_key` says which. */
    case Failed = 'failed';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Failed => 'Failed',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Nothing further will be dispatched for a run in one of these states. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Failed, self::Completed, self::Cancelled => true,
            self::Pending, self::Running => false,
        };
    }
}
