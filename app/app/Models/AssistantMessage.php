<?php

declare(strict_types=1);

namespace App\Models;

use App\Ai\Assistant\Assistant;
use App\Models\Concerns\BelongsToProject;
use App\Support\Metering\ProjectSpend;
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
 * The metering columns are the same columns a pipeline step carries, name for
 * name, and they are only ever set on the assistant's own row — the person's
 * message and the tool receipts cost nothing to write. See
 * {@see Assistant::meter()} and
 * {@see ProjectSpend}.
 *
 * @property string $role
 * @property string|null $body
 * @property string|null $tool_name
 * @property array<string, mixed>|null $tool_arguments
 * @property array<string, mixed>|null $tool_result
 * @property string|null $provider
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_micros
 * @property int|null $latency_ms
 * @property int|null $price_list_version
 */
class AssistantMessage extends Model
{
    use BelongsToProject;
    use HasUlids;

    public const string USER = 'user';

    public const string ASSISTANT = 'assistant';

    public const string TOOL = 'tool';

    protected $guarded = [];

    /**
     * The metering columns default in the database, but a row that was just
     * created has never read them back — so `$message->cost_micros` is null on
     * the instance that made it and `0` on every instance after. Exactly the
     * trap {@see Project} documents on its json columns, and worse here,
     * because the reader is arithmetic: a ceiling that adds null to an int gets
     * a deprecation in php 8 and a wrong answer in any version.
     *
     * It bites only on the turn that recorded no usage — a provider that fell
     * over before the first request — which is the least-exercised path and the
     * hardest place to notice.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cost_micros' => 0,
    ];

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
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            // Postgres returns bigint as a string through PDO, and a cost
            // ceiling comparing a string to an int is a comparison that stops
            // being true somewhere past nine digits.
            'cost_micros' => 'integer',
            'latency_ms' => 'integer',
            'price_list_version' => 'integer',
        ];
    }
}
