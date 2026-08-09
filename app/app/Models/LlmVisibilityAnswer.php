<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Database\Factories\LlmVisibilityAnswerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One assistant's answer to one prompt, on one day.
 *
 * `mentioned` is nullable and that is the whole design: null means the
 * assistant was asked and declined, which must not be counted as "answered
 * without us". Treating a refusal as a miss makes an outage look like a
 * collapse in visibility.
 *
 * @property string $id
 * @property string $llm_prompt_id
 * @property string $platform
 * @property string|null $model
 * @property Carbon $asked_on
 * @property bool|null $mentioned
 * @property string|null $excerpt
 * @property list<array{url: string, title: string}> $citations
 * @property list<string> $brands
 * @property string $money_spent
 */
class LlmVisibilityAnswer extends Model
{
    use BelongsToProject;

    /** @use HasFactory<LlmVisibilityAnswerFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'llm_prompt_id',
        'platform',
        'model',
        'asked_on',
        'mentioned',
        'excerpt',
        'citations',
        'brands',
        'money_spent',
    ];

    /**
     * The json columns default in the database, but a row that was just created
     * has never read them back — so these are null on the instance that made
     * the row and `[]` on every instance after. Anything that iterates one
     * fatals exactly once, on the request that created it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'citations' => '[]',
        'brands' => '[]',
    ];

    /**
     * @return BelongsTo<LlmPrompt, $this>
     */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(LlmPrompt::class, 'llm_prompt_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asked_on' => 'date',
            'mentioned' => 'boolean',
            'citations' => 'array',
            'brands' => 'array',
        ];
    }
}
