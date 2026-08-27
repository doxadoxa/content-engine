<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something an administrator did to somebody else's project.
 *
 * Written for every mutation the panel offers, and unconditionally: the whole
 * value of a log like this is that it has no exceptions in it. A comped plan, a
 * trial extended, a project paused — each is a decision somebody made about a
 * customer's service, and six months later "why is this account on Enterprise"
 * has to have an answer that is not a guess.
 *
 * Before and after as json rather than a message, because "what changed" is the
 * question this exists to answer and a sentence would have to be parsed to
 * answer it.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $project_id
 * @property array<string, mixed> $before
 * @property array<string, mixed> $after
 * @property Carbon|null $created_at
 */
class AdminAction extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'project_id', 'before', 'after', 'created_at'];

    protected $attributes = [
        'before' => '{}',
        'after' => '{}',
    ];

    /**
     * Write one.
     *
     * A static rather than an event listener. Every caller is a deliberate
     * administrative action in a controller, and a listener would make it
     * possible to add a seventh action that quietly logs nothing — which is the
     * one failure mode a log has.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function record(
        ?User $actor,
        string $action,
        ?Project $project,
        array $before,
        array $after,
    ): self {
        return static::query()->create([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'project_id' => $project?->getKey(),
            'before' => $before,
            'after' => $after,
            'created_at' => Carbon::now(),
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
