<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

use Illuminate\Support\Carbon;

final readonly class Windows
{
    public Carbon $from;

    public Carbon $to;

    public Carbon $currentFrom;

    public Carbon $previousTo;

    public function __construct(?Carbon $ending = null)
    {
        // Google Search Console dates are Pacific. Exclude the most recent
        // three days conservatively; individual providers still report quality.
        $latest = Carbon::today('America/Los_Angeles')->subDays(3);
        $this->to = ($ending === null || $ending->greaterThan($latest) ? $latest : $ending->copy())->startOfDay();
        $this->currentFrom = $this->to->copy()->subDays(27);
        $this->previousTo = $this->currentFrom->copy()->subDay();
        $this->from = $this->previousTo->copy()->subDays(27);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'current' => ['from' => $this->currentFrom->toDateString(), 'to' => $this->to->toDateString(), 'days' => 28],
            'previous' => ['from' => $this->from->toDateString(), 'to' => $this->previousTo->toDateString(), 'days' => 28],
            'excluded_recent_days' => 3,
            'search_timezone' => 'America/Los_Angeles',
        ];
    }
}
