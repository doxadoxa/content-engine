<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialKpi;
use App\Models\Concerns\BelongsToProject;
use Database\Factories\ContentGoalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What one month of social is trying to move, and by how much.
 *
 * The missing half of the assistant's proposal. A plan could say what to post
 * every day of a month and never say what any of it was for, which made
 * "accept this month" a decision with nothing riding on it and left the Studio
 * with no number to report progress against.
 *
 * Survives refinement on purpose — see the migration for why this is a table
 * and not four columns on `content_plans`.
 *
 * @property string $id
 * @property string $project_id
 * @property Carbon $month
 * @property SocialKpi $kpi
 * @property int $target
 * @property int $cadence
 * @property list<array{objective: string}> $weeks
 * @property Carbon|null $confirmed_at
 */
class ContentGoal extends Model
{
    use BelongsToProject;

    /** @use HasFactory<ContentGoalFactory> */
    use HasFactory;

    use HasUlids;

    /** Four, because the reference's week counter has to end somewhere. */
    public const int WEEKS = 4;

    protected $fillable = [
        'month',
        'kpi',
        'target',
        'cadence',
        'weeks',
        'confirmed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'weeks' => '[]',
    ];

    /** The goal for a month, whether or not anybody has confirmed it. */
    public static function forMonth(Carbon $month): ?self
    {
        return self::query()->whereDate('month', $month->copy()->startOfMonth())->first();
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * Which week of the goal today falls in, 1–4, or null outside it.
     *
     * Arithmetic on the month rather than on `created_at`: an operator who sets
     * a goal on the 20th is in week three of that month, not week one of their
     * own. The month is the unit everything else here is keyed by, and a week
     * counter that disagreed with the calendar would be the one number on the
     * header nobody could reconcile.
     *
     * Capped at {@see WEEKS} so the 29th to the 31st are week four rather than
     * a fifth week the goal has no objective for.
     */
    public function weekOf(Carbon $on): ?int
    {
        $start = $this->month->copy()->startOfMonth();

        if ($on->lessThan($start) || $on->greaterThan($start->copy()->endOfMonth())) {
            return null;
        }

        return (int) min(self::WEEKS, intdiv($on->day - 1, 7) + 1);
    }

    /**
     * How many posts the cadence promises for the whole month.
     *
     * Four weeks of it, matching {@see WEEKS}, rather than the number of weeks
     * the calendar happens to give this month. The goal is a four-week promise
     * and February must not quietly ask for less than March.
     */
    public function plannedPosts(): int
    {
        return $this->cadence * self::WEEKS;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'kpi' => SocialKpi::class,
            'target' => 'integer',
            'cadence' => 'integer',
            'weeks' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }
}
