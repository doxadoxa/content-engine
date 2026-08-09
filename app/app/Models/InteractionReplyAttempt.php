<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReplyAttemptOutcome;
use App\Enums\ReplyRoute;
use App\Models\Concerns\BelongsToProject;
use App\Social\InteractionReplySender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to answer a conversation — written before the call (§9).
 *
 * The reason this is a table and not a column on {@see Interaction}: the two
 * ways a reply can reach the platform and leave no trace both end in a rolled
 * back transaction. A publish request that leaves and never answers, and a
 * successful publish whose `markAnswered()` loses a race — in both cases every
 * write the transaction made is undone, including any warning it wrote onto the
 * conversation. A record that only survives the happy path is not a record of a
 * failure. So the attempt is opened in its own short transaction, the HTTP
 * happens outside both, and the close is a second short one; whatever the
 * outcome, the row is still there.
 *
 * Nothing here transitions anything. {@see InteractionReplySender} owns the
 * lifecycle, and the only thing the rest of the application asks this class is
 * {@see scopeOpenQuestion()} — whether a conversation is carrying an attempt
 * that may already be live in the thread.
 *
 * @property string $id
 * @property string $project_id
 * @property string $interaction_id
 * @property int|null $user_id
 * @property ReplyRoute $route
 * @property ReplyAttemptOutcome $outcome
 * @property string $text
 * @property string|null $reply_external_id
 * @property list<string> $acknowledged
 * @property array<string, mixed>|null $findings
 * @property string|null $detail
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class InteractionReplyAttempt extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $fillable = [
        'interaction_id',
        'user_id',
        'route',
        'outcome',
        'text',
        'reply_external_id',
        'acknowledged',
        'findings',
        'detail',
    ];

    /**
     * The database defaults these, but the instance that created the row has
     * never read them back — as on {@see Interaction}.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'outcome' => ReplyAttemptOutcome::InFlight->value,
        'acknowledged' => '[]',
    ];

    /**
     * @return BelongsTo<Interaction, $this>
     */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interaction::class);
    }

    /**
     * The operator who pressed the button. §4.2 requires there to have been one.
     *
     * @return BelongsTo<User, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Attempts that left the contents of a thread in doubt.
     *
     * `in_flight` is included deliberately: an attempt nobody closed is an
     * attempt whose publish call may have been the last thing that happened.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpenQuestion(Builder $query): void
    {
        $query->whereIn('outcome', ReplyAttemptOutcome::openQuestions());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'route' => ReplyRoute::class,
            'outcome' => ReplyAttemptOutcome::class,
            'acknowledged' => 'array',
            'findings' => 'array',
        ];
    }
}
