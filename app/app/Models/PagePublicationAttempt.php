<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PagePublicationAttempt extends Model
{
    use BelongsToProject, HasUlids;

    protected $guarded = ['id', 'project_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Delivery attempts are immutable.'));
        static::deleting(fn () => throw new LogicException('Delivery attempts are retained.'));
    }
}
