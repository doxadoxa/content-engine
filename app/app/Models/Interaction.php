<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InteractionState;
use App\Models\Concerns\BelongsToProject;
use App\Support\Content\ConcurrentStateChange;
use App\Support\Content\InvalidStateTransition;
use Database\Factories\InteractionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One incoming reply or mention, owed an answer (§3).
 *
 * A row here is not a record of something that happened — it is a debt. §4.2
 * puts the target latency in minutes because the algorithm weighs the speed of
 * the first hour, so the shape that matters is "what is still open", which is
 * what {@see scopeOpen()} answers.
 *
 * State changes only ever go through {@see transitionTo()} and the named
 * methods over it, exactly as on {@see ContentItem} — and, as there, `state` is
 * deliberately absent from `$fillable` so a request body cannot reach it.
 * `answered` means a reply left the building; it must be reachable from one
 * place. `answered_at` and `reply_external_id` are absent for the same reason
 * and not a weaker one: they are the evidence that a reply was sent, they are
 * stamped by the machine below, and a webhook body that could set them could
 * make a conversation look answered without one. Factories still set all three
 * directly, because Laravel unguards them.
 *
 * Nothing here sends anything. §4.2 forbids autopublish in this contour
 * permanently, so {@see markAnswered()} records a reply a human approved rather
 * than causing one.
 *
 * @property string $id
 * @property string $project_id
 * @property string $channel_id
 * @property string|null $signal_id
 * @property string $external_id
 * @property string|null $parent_external_id
 * @property string|null $root_external_id
 * @property string $author
 * @property string|null $author_handle
 * @property string $text
 * @property string|null $permalink
 * @property Carbon $received_at
 * @property InteractionState $state
 * @property string|null $draft_reply
 * @property Carbon|null $draft_generated_at
 * @property array<string, mixed>|null $draft_guard
 * @property Carbon|null $answered_at
 * @property string|null $reply_external_id
 * @property string|null $ignored_reason
 * @property array<string, mixed> $raw
 */
class Interaction extends Model
{
    use BelongsToProject;

    /** @use HasFactory<InteractionFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'channel_id',
        'signal_id',
        'external_id',
        'parent_external_id',
        'root_external_id',
        'author',
        'author_handle',
        'text',
        'permalink',
        'received_at',
        'draft_reply',
        'draft_generated_at',
        'draft_guard',
        'ignored_reason',
        'raw',
    ];

    /**
     * As on {@see ContentItem}: the database defaults these, but the instance
     * that created the row has never read them back, so `$row->raw` would be
     * null exactly once — on the request that made it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => InteractionState::New->value,
        'raw' => '{}',
    ];

    /**
     * The channel the conversation arrived on and would be answered through.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * The signal this conversation also became, when the listener decided it
     * was a reason to write something as well as something to answer.
     *
     * @return BelongsTo<Signal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    /** The operator threw the draft away. Back into the queue, unanswered. */
    public function discardDraft(): void
    {
        $this->transitionTo(InteractionState::New);
    }

    /** Deliberately left alone, with the reason §7 makes mandatory. */
    public function ignore(string $reason): void
    {
        $this->ignored_reason = $reason;

        $this->transitionTo(InteractionState::Ignored);
    }

    /** A reply the operator approved has been sent. */
    public function markAnswered(string $replyExternalId): void
    {
        $this->reply_external_id = $replyExternalId;

        $this->transitionTo(InteractionState::Answered);
    }

    /** The engine wrote a reply. It waits for a human — always (§4.2). */
    public function markDrafted(string $draft): void
    {
        $this->draft_reply = $draft;
        $this->draft_generated_at = now();

        $this->transitionTo(InteractionState::Drafted);
    }

    /**
     * The one door into the state machine.
     *
     * Compare-and-set, on the same reasoning as `PipelineRunner::claim()` and
     * for a worse failure. Checking `canTransitionTo()` against the state
     * in memory and then writing `UPDATE … WHERE id = ?` is last-write-wins: a
     * drafting job reads a conversation in `new`, spends twenty seconds in a
     * model call, and comes back to write `drafted` over a row the operator
     * answered meanwhile — `answered_at` and `reply_external_id` untouched
     * underneath it. The conversation returns to {@see scopeOpen()} carrying a
     * fresh draft, and a second reply goes into a thread that already had one.
     * That is the single outcome §4.2 exists to prevent, so the previous state
     * is a predicate on the write and an update that changes no row is an
     * error rather than a no-op.
     *
     * @throws InvalidStateTransition when the map has no such edge
     * @throws ConcurrentStateChange when the row moved while this was prepared
     */
    public function transitionTo(InteractionState $next): void
    {
        $previous = $this->state;

        if (! $previous->canTransitionTo($next)) {
            throw InvalidStateTransition::betweenInteractionStates($previous, $next);
        }

        $this->state = $next;

        // Stamped here rather than by the caller so the timestamp cannot
        // disagree with the state. It is the number §4.2 is judged on —
        // `answered_at - received_at` is the latency the algorithm rewards.
        if ($next === InteractionState::Answered) {
            $this->answered_at = now();
        }

        // Drafted → New is the operator throwing a draft away, and the draft
        // has to go with it. InteractionState's own docblock says why: a `new`
        // conversation still carrying a reply nobody intends to send is worse
        // than an honest empty queue entry, because the duty screen shows the
        // text and the next drafting run has no reason to replace it.
        if ($next === InteractionState::New) {
            $this->draft_reply = null;
            $this->draft_generated_at = null;
            // And the findings with it. `draft_guard` is a verdict *on* the
            // draft, so a verdict outliving the text it judged would sit on the
            // duty screen as "this reply names a forbidden topic" next to no
            // reply at all.
            $this->draft_guard = null;
        }

        $this->updated_at = now();

        $values = $this->getDirty();

        // This write does not fire the `updating` event BelongsToProject uses
        // to refuse a tenant reassignment, so the column that guard protects is
        // simply not written from here.
        unset($values['project_id']);

        $written = static::query()
            ->whereKey($this->getKey())
            ->where('state', $previous->value)
            ->update(array_map(
                // The query builder does not apply the model's casts, so the
                // json columns have to be encoded here.
                static fn (mixed $value): mixed => is_array($value) ? json_encode($value) : $value,
                $values,
            ));

        if ($written !== 1) {
            $actual = static::query()->whereKey($this->getKey())->value('state');

            throw ConcurrentStateChange::forInteraction(
                (string) $this->getKey(),
                $previous,
                $next,
                $actual instanceof InteractionState ? $actual : null,
            );
        }

        $this->syncOriginal();
    }

    /**
     * The duty screen's only query: still owed an answer, oldest first.
     *
     * Oldest first rather than newest, which is the opposite of every other
     * list in the engine and is the whole point — a queue sorted newest first
     * quietly buries the conversation that has been waiting longest, and
     * waiting is the thing this contour is measured on.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query
            ->whereIn('state', [InteractionState::New, InteractionState::Drafted])
            ->orderBy('received_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => InteractionState::class,
            'received_at' => 'datetime',
            'draft_generated_at' => 'datetime',
            'draft_guard' => 'array',
            'answered_at' => 'datetime',
            'raw' => 'array',
        ];
    }
}
