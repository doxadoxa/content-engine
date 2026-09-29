<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every delivery id the blog has already acted on.
 *
 * @property int $id
 * @property string $delivery_id
 * @property string $event
 * @property string|null $engine_id
 * @property string|null $locale
 * @property Carbon $received_at
 */
class BlogDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = ['delivery_id', 'event', 'engine_id', 'locale', 'received_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
