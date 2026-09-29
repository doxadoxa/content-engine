<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A deletion that arrived before the post it withdraws was ever stored.
 *
 * @property int $id
 * @property string $engine_id
 * @property string $locale
 * @property Carbon|null $source_sent_at
 */
class BlogDeletion extends Model
{
    protected $fillable = ['engine_id', 'locale', 'source_sent_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['source_sent_at' => 'datetime'];
    }
}
