<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialLoginProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One account, as one outside provider knows it.
 *
 * Nothing here is a credential: no token is kept, because nothing in this
 * application ever calls Google *as* the person who signed in. The grant is
 * used once, in the callback, to learn who they are — and then discarded. What
 * survives is this row, which is only useful for recognising them next time.
 *
 * @property int $id
 * @property int $user_id
 * @property SocialLoginProvider $provider
 * @property string $provider_subject
 * @property string|null $email
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OauthIdentity extends Model
{
    protected $fillable = [
        'provider',
        'provider_subject',
        'email',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialLoginProvider::class,
        ];
    }
}
