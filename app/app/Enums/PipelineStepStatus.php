<?php

declare(strict_types=1);

namespace App\Enums;

enum PipelineStepStatus: string
{
    /** Waiting for its dependencies, or waiting to be picked up. */
    case Pending = 'pending';

    /** Claimed by an attempt. */
    case Running = 'running';

    case Succeeded = 'succeeded';

    /** This attempt failed. A retry moves it back through Running. */
    case Failed = 'failed';

    /** Deliberately not run — its work was unnecessary, not impossible. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    /**
     * Done with, one way or another.
     *
     * Both meanings matter to the scheduler: a settled step is never run again
     * on resume, and a dependent step is released the moment every one of its
     * dependencies is settled — skipping a step must not strand what follows.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Succeeded, self::Skipped => true,
            self::Pending, self::Running, self::Failed => false,
        };
    }
}
