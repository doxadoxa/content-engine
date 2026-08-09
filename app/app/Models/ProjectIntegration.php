<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IntegrationProvider;
use App\Models\Concerns\BelongsToProject;
use Database\Factories\ProjectIntegrationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One project's grant to read one outside account.
 *
 * The tokens are cast `encrypted` and the model is `$hidden` them, so the two
 * ways they would otherwise escape — a database dump and a model serialised
 * into a page prop — are both closed by construction rather than by everyone
 * remembering.
 *
 * @property string $id
 * @property string $project_id
 * @property IntegrationProvider $provider
 * @property string|null $refresh_token
 * @property string|null $access_token
 * @property Carbon|null $access_token_expires_at
 * @property list<string> $scopes
 * @property array<string, mixed> $config
 * @property int|null $connected_by_id
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_synced_at
 * @property string|null $failure_reason
 */
class ProjectIntegration extends Model
{
    use BelongsToProject;

    /** @use HasFactory<ProjectIntegrationFactory> */
    use HasFactory;

    use HasUlids;

    /** Read what we publish, and nothing else. */
    public const string SCOPE_SEARCH_CONSOLE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public const string SCOPE_ANALYTICS = 'https://www.googleapis.com/auth/analytics.readonly';

    protected $fillable = [
        'provider',
        'refresh_token',
        'access_token',
        'access_token_expires_at',
        'scopes',
        'config',
        'connected_by_id',
        'connected_at',
        'last_synced_at',
        'failure_reason',
    ];

    protected $attributes = [
        'scopes' => '[]',
        'config' => '{}',
    ];

    /**
     * Never serialised into a response. A page prop is one `->toArray()` away
     * from a token in the HTML of a page somebody screenshots.
     *
     * @var list<string>
     */
    protected $hidden = ['refresh_token', 'access_token'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_id');
    }

    /** The Search Console property, as Google names it. */
    public function searchConsoleSite(): ?string
    {
        $site = $this->config['search_console_site'] ?? null;

        return is_string($site) && $site !== '' ? $site : null;
    }

    /** The GA4 property, as `properties/123456789`. */
    public function analyticsProperty(): ?string
    {
        $property = $this->config['analytics_property'] ?? null;

        return is_string($property) && $property !== '' ? $property : null;
    }

    public function grants(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Usable means: not broken, and there is something to refresh with.
     *
     * A connection whose refresh token Google has stopped honouring still has
     * rows in this table; what it does not have is the ability to answer, and
     * every caller has to be able to tell those apart.
     */
    public function isUsable(): bool
    {
        return $this->failure_reason === null && $this->getRawOriginal('refresh_token') !== null;
    }

    public function markBroken(string $reason): void
    {
        $this->forceFill([
            'failure_reason' => $reason,
            'access_token' => null,
            'access_token_expires_at' => null,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'scopes' => 'array',
            'config' => 'array',
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
