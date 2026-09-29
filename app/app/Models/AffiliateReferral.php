<?php

declare(strict_types=1);

namespace App\Models;

use App\Affiliates\Referrals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An account a partner referred, and whether we may still say so.
 *
 * See the migration for why each column is kept, and {@see Referrals} for who
 * writes them.
 *
 * @property int $id
 * @property int $user_id
 * @property string $visitor_id
 * @property string $email
 * @property string|null $consent_version
 * @property Carbon|null $consented_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AffiliateReferral extends Model
{
    /**
     * How long a marketing consent stands, which is how long the browser keeps
     * the record of it — `MAX_AGE_SECONDS` in resources/js/lib/consent.ts.
     * After that the banner asks again, and until it has an answer nothing is
     * reported: a customer on an annual renewal would otherwise be reported
     * forever on the strength of one click.
     */
    public const int CONSENT_LIFETIME_DAYS = 365;

    protected $fillable = [
        'user_id',
        'visitor_id',
        'email',
        'consent_version',
        'consented_at',
    ];

    /**
     * Whether the consent this referral rests on still stands: given, not
     * withdrawn, for the cookie inventory we publish now, and within the
     * twelve months it was given for.
     */
    public function mayReport(): bool
    {
        return $this->consent_version !== null
            && $this->consent_version === (string) config('legal.consent_version')
            && $this->consented_at !== null
            && $this->consented_at->greaterThan(now()->subDays(self::CONSENT_LIFETIME_DAYS));
    }

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
            'consented_at' => 'datetime',
        ];
    }
}
