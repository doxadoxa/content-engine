<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An attempt is durable before a paid request; only its single outcome may be recorded afterward.
 *
 * @property string $id
 * @property string $project_id
 * @property string $pipeline_run_id
 * @property string $step_key
 * @property string $status
 * @property string $provider
 * @property string $model
 * @property string $role
 * @property int $price_list_version
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $cost_micros
 * @property string|null $cost_basis
 * @property CarbonImmutable $created_at
 */
class ProviderSpendRecord extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['pipeline_run_id', 'step_key', 'status', 'provider', 'model', 'role', 'price_list_version', 'request_hash', 'response_hash', 'input_tokens', 'output_tokens', 'cost_micros', 'cost_basis', 'finished_at'];

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->getOriginal('status') !== 'pending' || $record->isDirty(['pipeline_run_id', 'step_key', 'role', 'price_list_version', 'request_hash'])) {
                throw new LogicException('Provider spending evidence is immutable after its outcome is recorded.');
            }
        });
        static::deleting(fn () => throw new LogicException('Provider spending evidence is retained.'));
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['price_list_version' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'cost_micros' => 'integer', 'created_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
