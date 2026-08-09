<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelType;
use App\Models\Concerns\BelongsToProject;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A publishing target (§2).
 *
 * Phase 2 stores it; phase 6 delivers through it. The secret is cast
 * `encrypted`, so it is ciphertext at rest and a database dump — the thing most
 * likely to leave the machine — carries no usable token.
 *
 * @property string $id
 * @property string $project_id
 * @property ChannelType $type
 * @property string $name
 * @property array<string, mixed> $config
 * @property string|null $secret
 * @property string|null $token_hash
 * @property bool $is_enabled
 * @property bool $autopublish
 * @property Carbon|null $verified_at
 */
class Channel extends Model
{
    use BelongsToProject;

    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'type',
        'name',
        'config',
        'secret',
        'is_enabled',
        'autopublish',
        'verified_at',
        'token_hash',
    ];

    protected $attributes = [
        'config' => '{}',
    ];

    /**
     * Kept out of every array and JSON rendering of the model. The cast
     * decrypts on read, so without this an `Inertia::render` of a channel list
     * would put the plaintext token in the page's props.
     *
     * @var list<string>
     */
    protected $hidden = ['secret', 'token_hash'];

    public static function booted(): void
    {
        static::saving(function (self $channel): void {
            $channel->token_hash = $channel->type === ChannelType::PullApi && $channel->secret !== null
                ? self::fingerprintPullToken($channel->secret)
                : null;
        });
    }

    public static function fingerprintPullToken(string $token): string
    {
        $key = (string) config('publishing.pull_token_hash_key', config('app.key'));

        return hash_hmac('sha256', $token, $key);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function hasSecret(): bool
    {
        return $this->getRawOriginal('secret') !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'config' => 'array',
            'secret' => 'encrypted',
            'is_enabled' => 'boolean',
            'autopublish' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }
}
