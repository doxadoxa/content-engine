<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $project_id
 * @property string $publication_id
 * @property string $site_page_id
 * @property string $channel_id
 * @property string|null $recovery_of_id
 * @property string $kind
 * @property string $status
 * @property string $delivery_id
 * @property string $request_body
 * @property string $request_hash
 * @property array<string,mixed> $destination
 * @property string|null $before_snapshot_id
 * @property string|null $after_snapshot_id
 * @property int $attempts
 * @property Carbon|null $dispatch_started_at
 * @property Carbon|null $retry_at
 * @property Carbon|null $committed_at
 * @property Carbon|null $verified_at
 * @property Carbon $authorized_at
 * @property string|null $last_error
 * @property array<string,mixed>|null $verification_results
 * @property-read PagePublication $publication
 * @property-read Channel $channel
 * @property-read SitePage $page
 * @property-read PageSnapshot|null $beforeSnapshot
 * @property-read PageSnapshot|null $afterSnapshot
 * @property-read PagePublicationOperation|null $recoveryOf
 */
class PagePublicationOperation extends Model
{
    use BelongsToProject, HasUlids;

    protected $guarded = ['id', 'project_id'];

    /** @var list<string> */
    protected $hidden = ['request_body'];

    /** @return BelongsTo<PagePublication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(PagePublication::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<SitePage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'site_page_id');
    }

    /** @return BelongsTo<PageSnapshot, $this> */
    public function beforeSnapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'before_snapshot_id');
    }

    /** @return BelongsTo<PageSnapshot, $this> */
    public function afterSnapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'after_snapshot_id');
    }

    /** @return BelongsTo<PagePublicationOperation, $this> */
    public function recoveryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recovery_of_id');
    }

    /** @return HasMany<PagePublicationAttempt, $this> */
    public function attemptsLog(): HasMany
    {
        return $this->hasMany(PagePublicationAttempt::class, 'operation_id')->orderBy('number');
    }

    protected static function booted(): void
    {
        static::updating(function (self $operation): void {
            if ($operation->isDirty(['publication_id', 'site_page_id', 'channel_id', 'recovery_of_id', 'kind', 'delivery_id', 'request_body', 'request_hash', 'destination', 'authorized_by', 'authorized_at'])) {
                throw new LogicException('An authorized operation is immutable. Reconcile the original identity.');
            }
            if ($operation->getRawOriginal('committed_at') !== null && $operation->isDirty(['before_snapshot_id', 'after_snapshot_id', 'committed_at'])) {
                throw new LogicException('A confirmed receiver result is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Publication operations are retained.'));
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['destination' => 'array', 'attempts' => 'integer', 'authorized_at' => 'datetime', 'dispatch_started_at' => 'datetime', 'retry_at' => 'datetime', 'committed_at' => 'datetime', 'verified_at' => 'datetime', 'verification_results' => 'array'];
    }
}
