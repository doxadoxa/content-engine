<?php

declare(strict_types=1);

namespace App\Pages;

use App\Billing\Entitlements;
use App\Enums\SitePageKind;
use App\Models\ContentItem;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\SitePage;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TrackedPages
{
    public function __construct(private readonly PublicPageReader $reader, private readonly CurrentProject $current) {}

    public function capture(Project $project, SitePage $page): SitePage
    {
        abort_unless($page->project_id === $project->id && $page->tracked_at !== null, 409, 'This page is no longer tracked.');

        return $this->track($project, (string) $page->canonical_url, (string) $page->locale, $page->page_kind->value, $page->id);
    }

    /**
     * `$preferDeclaredLocale` treats `$locale` as a guess the page's declared
     * language may correct — for callers that inferred it from a URL rather
     * than asked the owner.
     */
    public function track(Project $project, string $url, string $locale, string $kind, ?string $expectedTrackedId = null, bool $preferDeclaredLocale = false): SitePage
    {
        $locales = array_values(array_unique([$project->default_locale, ...$project->locales]));
        if (! in_array($locale, $locales, true)) {
            throw ValidationException::withMessages(['locale' => 'Select one of this project’s languages.']);
        }
        if (! in_array($kind, ['commercial', 'editorial', 'other'], true)) {
            throw ValidationException::withMessages(['kind' => 'Choose a supported page type.']);
        }
        $read = $this->reader->read($project, $url, $locale, $preferDeclaredLocale);
        $locale = $read['locale'];

        return $this->current->run($project, fn () => DB::transaction(function () use ($project, $url, $locale, $kind, $read, $expectedTrackedId): SitePage {
            // Serialise public-identity decisions, including aliases and old ContentItem URLs.
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $canonical = $read['canonical_url'];
            $hash = hash('sha256', $canonical);
            $requested = PageUrl::normalize($url);
            $known = SitePage::query()->tracked()->where(fn ($query) => $query->where('url', $requested)->orWhere('canonical_url', $requested))->first();
            if ($known !== null && $known->canonical_hash !== $hash) {
                throw ValidationException::withMessages(['url' => 'This page now declares a different canonical URL. Its existing identity and history were preserved. Pause the old identity before importing the new canonical page.']);
            }
            $page = $expectedTrackedId !== null ? SitePage::query()->whereKey($expectedTrackedId)->lockForUpdate()->first() : SitePage::query()->where('canonical_hash', $hash)->first();
            if ($expectedTrackedId !== null) {
                abort_unless($page !== null && $page->tracked_at !== null && $page->canonical_hash === $hash && $page->locale === $locale, 409, 'Tracking or page identity changed during the public read. The page was not reactivated.');
            }
            if ($page !== null && $page->locale !== null && $page->locale !== $locale) {
                throw ValidationException::withMessages(['locale' => 'This canonical page is already tracked in another language. Check the page’s canonical URL.']);
            }
            $page ??= SitePage::query()->where('url', $canonical)->first()
                ?? SitePage::query()->where('url', PageUrl::normalize($url))->whereNull('canonical_hash')->first()
                ?? new SitePage(['url' => $canonical]);
            $item = ContentItem::query()->whereNotNull('public_url')->get()->first(
                fn (ContentItem $item): bool => in_array(PageUrl::normalize((string) $item->public_url), [$canonical, $read['url'], PageUrl::normalize($url)], true),
            );
            if ($item !== null && $item->locale !== $locale) {
                throw ValidationException::withMessages(['locale' => 'The existing generated article uses another language.']);
            }
            $trackedLimit = app(Entitlements::class)->for($project)->limit('tracked_pages');
            if ($item === null && $page->content_item_id === null && $page->tracked_at === null && $trackedLimit !== null && SitePage::query()->tracked()->whereNull('content_item_id')->count() >= $trackedLimit) {
                throw ValidationException::withMessages(['url' => 'This plan tracks up to '.$trackedLimit.' existing pages, plus articles published with Avyo. Pause another page before adding this one. Existing history stays available.']);
            }
            $page->fill([
                'title' => Str::limit($read['fields']['title'] ?: $canonical, 250, ''),
                'description' => $read['fields']['description'],
                'tracked_at' => $page->tracked_at ?? now(),
                'canonical_url' => $canonical, 'canonical_hash' => $hash, 'locale' => $locale,
                'content_item_id' => $item->id ?? $page->content_item_id,
                'page_kind' => SitePageKind::from($kind), 'is_article' => $kind === 'editorial',
            ])->save();
            // Do not write SitePage.body: that column is the separately curated commercial evidence corpus.
            PageSnapshot::query()->create([
                'site_page_id' => $page->id, 'source_kind' => 'public', 'source_url' => $read['url'],
                'captured_at' => now(), 'revision' => 'public:'.$read['content_hash'],
                'content_hash' => $read['content_hash'], 'fields' => $read['fields'], 'editable_fields' => [],
                'metadata' => ['requested_url' => $requested, 'canonical_url' => $canonical, 'locale' => $locale, 'editing_status' => 'public_observation_only', 'description_count' => $read['description_count'], 'fact_surfaces' => $read['fact_surfaces']],
            ]);

            return $page->fresh(['latestSnapshot']);
        }));
    }
}
