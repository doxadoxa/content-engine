<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromptIntent;
use App\Models\Concerns\BelongsToProject;
use Database\Factories\LlmPromptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One question a buyer might type into an assistant.
 *
 * The instrument, not the reading. Written once and asked repeatedly, because a
 * score measured against a different question each week is not a trend.
 *
 * @property string $id
 * @property string $text
 * @property string $locale
 * @property PromptIntent $intent
 * @property bool $is_active
 * @property Carbon $created_at
 */
class LlmPrompt extends Model
{
    use BelongsToProject;

    /** @use HasFactory<LlmPromptFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'text',
        'locale',
        'intent',
        'is_active',
    ];

    /**
     * @return HasMany<LlmVisibilityAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(LlmVisibilityAnswer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intent' => PromptIntent::class,
            'is_active' => 'boolean',
        ];
    }
}
