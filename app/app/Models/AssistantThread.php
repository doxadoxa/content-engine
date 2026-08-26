<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One conversation about one subject.
 *
 * Named, and addressable. The first version of this was a single endless thread
 * per project — which put the chat on the landing screen with no way back into
 * it and no way to hold two subjects apart. A person discussing this month's
 * plan, the Portuguese visibility gap and the dead deliveries is having three
 * conversations, and a single scroll makes all three harder to return to than
 * none of them would be.
 *
 * @property string|null $title
 * @property Carbon|null $last_message_at
 */
class AssistantThread extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $guarded = [];

    /**
     * A new conversation, named after the thing that started it.
     *
     * Derived rather than asked for, and derived rather than generated: naming
     * is instant and free here, and a round trip to a model for a title would
     * put a second wait in front of the first answer — for a string the
     * conversation itself is about to make obvious.
     */
    public static function start(string $firstMessage): self
    {
        $title = Str::limit(
            trim(Str::before(trim($firstMessage), "\n")),
            60,
            '',
            preserveWords: true,
        );

        return static::query()->create([
            'title' => $title === '' ? 'New conversation' : $title,
            'last_message_at' => now(),
        ]);
    }

    /** @return HasMany<AssistantMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class)->oldest();
    }

    /**
     * Newest conversation first, by when something was last said in it.
     *
     * Deliberately not `updated_at`: renaming a thread, or any other write to
     * the row, must not push it to the top of a list that claims to be ordered
     * by activity.
     *
     * @param  Builder<AssistantThread>  $query
     * @return Builder<AssistantThread>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('last_message_at')->orderByDesc('created_at');
    }

    public function touchConversation(): void
    {
        $this->forceFill(['last_message_at' => now()])->save();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }
}
