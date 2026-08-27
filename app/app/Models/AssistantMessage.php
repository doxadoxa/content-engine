<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn: something a person said, something the engine said, or something
 * the engine did.
 *
 * The third kind is why this is a table rather than a blob. A tool call is the
 * moment the conversation stopped being talk and changed the project, and it
 * has to be as legible afterwards as it was at the time — which means the name,
 * the arguments and the result, kept apart from any prose about them.
 *
 * @property string $role
 * @property string|null $body
 * @property string|null $tool_name
 * @property array<string, mixed>|null $tool_arguments
 * @property array<string, mixed>|null $tool_result
 */
class AssistantMessage extends Model
{
    use BelongsToProject;
    use HasUlids;

    public const string USER = 'user';

    public const string ASSISTANT = 'assistant';

    public const string TOOL = 'tool';

    protected $guarded = [];

    /** @return BelongsTo<AssistantThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(AssistantThread::class, 'assistant_thread_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tool_arguments' => 'array',
            'tool_result' => 'array',
        ];
    }
}
