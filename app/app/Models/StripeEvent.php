<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Stripe event we have already acted on.
 *
 * Stripe delivers at least once and says so, so this is what stops a replayed
 * `invoice.payment_succeeded` renewing a period twice — which would reset a
 * customer's counters mid-month and hand them a second month's quota for one
 * month's money.
 *
 * @property string $id
 * @property string $type
 * @property string|null $project_id
 * @property string|null $outcome
 */
class StripeEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'type', 'project_id', 'outcome', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
