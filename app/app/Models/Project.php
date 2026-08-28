<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Entitlements;
use App\Enums\LocaleMode;
use App\Enums\OnboardingStatus;
use App\Enums\ProjectStatus;
use App\Support\Duty\DutyHours;
use App\Support\Tenancy\ProjectScope;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A tenant: one site the engine writes for.
 *
 * ULID rather than an auto-increment key. Ids reach the outside world in
 * webhook payloads and panel URLs, where a sequential integer publishes how
 * many projects exist and makes a neighbouring tenant's id a guess away.
 *
 * The slug is what appears in panel URLs, but only because the panel asks for
 * it by name (`->tenant(Project::class, slugAttribute: 'slug')`). Overriding
 * getRouteKeyName() here instead would also rebind every other route that takes
 * a project — including the resource's own /{record}/edit.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property array<string, list<array{string, string}>>|null $duty_hours
 * @property list<string>|null $feed_urls
 * @property string $default_locale
 * @property list<string> $locales
 * @property array<string, string>|null $locale_modes
 * @property ProjectStatus $status
 * @property int $weekly_target
 * @property list<string> $research_seeds
 * @property int|null $minimum_volume
 * @property string $market
 * @property bool $is_ymyl
 * @property bool $autopublish
 * @property array<string, mixed> $original_data
 * @property list<array<string, mixed>> $authors
 * @property OnboardingStatus $onboarding_status
 * @property string|null $website_url
 * @property string|null $sitemap_url
 * @property array<string, mixed> $site_analysis
 * @property array<string, mixed> $onboarding
 * @property list<string> $competitors
 * @property array<string, mixed> $article_settings
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'duty_hours',
        'feed_urls',
        'default_locale',
        'locales',
        'locale_modes',
        'status',
        'weekly_target',
        'research_seeds',
        'minimum_volume',
        'market',
        'is_ymyl',
        'autopublish',
        'original_data',
        'authors',
        'onboarding_status',
        'website_url',
        'sitemap_url',
        'site_analysis',
        'onboarding',
        'onboarded_at',
        'competitors',
        'article_settings',
    ];

    /**
     * @return array<string, string>
     */
    /**
     * The json columns default in the database, but a model that was just
     * created has never read them back — so `$project->onboarding` is null on
     * the instance that made the row and `{}` on every instance after. Anything
     * that spreads or indexes it fatals exactly once, on the request that
     * created the project, which is the hardest possible time to notice.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'site_analysis' => '{}',
        'onboarding' => '{}',
        'competitors' => '[]',
        'article_settings' => '{}',
        'research_seeds' => '[]',
        'original_data' => '{}',
        'authors' => '[]',
        'locales' => '[]',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Every version, newest first. The active one is {@see brandBrief()}.
     *
     * Unscoped by design: these relations are read *from* a project, so the
     * project is already named, and the tenant scope would additionally require
     * that same project to be the current one — which it is not in a seeder or
     * a maintenance command.
     *
     * @return HasMany<BrandBrief, $this>
     */
    public function brandBriefs(): HasMany
    {
        return $this->hasMany(BrandBrief::class)
            ->withoutGlobalScope(ProjectScope::class)
            ->orderByDesc('version');
    }

    /**
     * @return HasOne<BrandBrief, $this>
     */
    public function brandBrief(): HasOne
    {
        return $this->hasOne(BrandBrief::class)
            ->withoutGlobalScope(ProjectScope::class)
            ->where('is_active', true);
    }

    /**
     * @return HasMany<Channel, $this>
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class)
            ->withoutGlobalScope(ProjectScope::class);
    }

    /**
     * @return HasMany<ContentPlan, $this>
     */
    public function contentPlans(): HasMany
    {
        return $this->hasMany(ContentPlan::class)
            ->withoutGlobalScope(ProjectScope::class);
    }

    /**
     * @return HasMany<ContentItem, $this>
     */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class)
            ->withoutGlobalScope(ProjectScope::class);
    }

    /**
     * Pages discovered on the project's own site before the engine wrote them.
     *
     * @return HasMany<SitePage, $this>
     */
    public function sitePages(): HasMany
    {
        return $this->hasMany(SitePage::class)
            ->withoutGlobalScope(ProjectScope::class);
    }

    /**
     * True when the project publishes in this locale. The default locale is
     * always included, so a project can never be configured into a state where
     * its own default is not publishable.
     */
    /**
     * Who makes this project's content in a language.
     *
     * Defaults to {@see LocaleMode::Adapt} for anything unlisted, which is both
     * backwards compatible — every locale used to get its own written article —
     * and the position worth defaulting to: a machine translation of prose that
     * scored 100 is prose nobody has scored, because every rule in the house
     * style is a property of the writing rather than of the meaning.
     *
     * The project's source language is not stored here. It follows the keyword,
     * which is the only thing that can decide it: a keyword lives in a language
     * and an article in another cannot rank for it.
     */
    public function localeMode(string $locale): LocaleMode
    {
        $modes = $this->locale_modes ?? [];

        return LocaleMode::tryFrom((string) ($modes[$locale] ?? '')) ?? LocaleMode::Adapt;
    }

    /**
     * The locales the engine writes for, default first.
     *
     * @return list<string>
     */
    public function writtenLocales(): array
    {
        return array_values(array_filter(
            array_unique(array_filter(array_map(trim(...), [$this->default_locale, ...$this->locales]))),
            fn (string $locale): bool => $this->localeMode($locale)->isWritten(),
        ));
    }

    /**
     * How much to write in a week, clamped to what the plan allows.
     *
     * The stored column is what the operator chose and is never overwritten:
     * somebody who set fourteen and then downgraded gets fourteen back when
     * they upgrade, rather than discovering the plan quietly rewrote a setting
     * of theirs. This is the number the engine acts on.
     *
     * A clamp rather than a counter, and that is the better limit of the two.
     * A counter stops a project dead on the 22nd of the month, which reads as a
     * broken engine; a clamped cadence makes the engine pace itself so the
     * month comes out even and the boundary is never felt. The article counter
     * behind it is the backstop for the paths that bypass the tick — the
     * Studio's buttons, an article somebody writes by hand.
     */
    public function weeklyTarget(): int
    {
        return app(Entitlements::class)->for($this)->weeklyTarget($this->weekly_target);
    }

    /**
     * When somebody is around to answer (§4.3, §11.4).
     *
     * Returned as the value object rather than as the raw column so that every
     * reader gets the same reading of a half-typed configuration — and, more to
     * the point, the same reading of an empty one, which is "never on duty".
     * A project that has not answered the onboarding question yet must not have
     * posts scheduled into a silence nobody is watching.
     */
    public function dutyHours(): DutyHours
    {
        return DutyHours::fromArray($this->duty_hours);
    }

    /**
     * The project's RSS whitelist (§4.1), as a plain list.
     *
     * Never null and never holding a blank, because the hourly listening run
     * iterates it and a null in the middle of that loop is an outage in the one
     * contour §4.1 says pays for itself on its own. The column is nullable
     * because "not answered yet" and "no feeds" are the same thing to every
     * reader; this is where that stops being the caller's problem.
     *
     * @return list<string>
     */
    public function feedUrls(): array
    {
        return array_values(array_filter(
            array_map(trim(...), $this->feed_urls ?? []),
            static fn (string $url): bool => $url !== '',
        ));
    }

    public function supportsLocale(string $locale): bool
    {
        return $locale === $this->default_locale
            || in_array($locale, $this->locales ?? [], true);
    }

    protected function casts(): array
    {
        return [
            'locales' => 'array',
            'locale_modes' => 'array',
            'duty_hours' => 'array',
            'feed_urls' => 'array',
            'status' => ProjectStatus::class,
            'weekly_target' => 'integer',
            'minimum_volume' => 'integer',
            'research_seeds' => 'array',
            'is_ymyl' => 'boolean',
            'autopublish' => 'boolean',
            'original_data' => 'array',
            'authors' => 'array',
            'onboarding_status' => OnboardingStatus::class,
            'site_analysis' => 'array',
            'onboarding' => 'array',
            'onboarded_at' => 'datetime',
            'competitors' => 'array',
            'article_settings' => 'array',
        ];
    }
}
