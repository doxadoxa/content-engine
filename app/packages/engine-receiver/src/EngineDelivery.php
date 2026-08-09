<?php

declare(strict_types=1);

namespace Persistance\EngineReceiver;

use Illuminate\Database\Eloquent\Model;

/** Every delivery id this site has already acted on. */
class EngineDelivery extends Model
{
    public $timestamps = false;

    protected $table = 'engine_deliveries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
