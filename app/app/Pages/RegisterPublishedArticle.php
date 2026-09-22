<?php

declare(strict_types=1);

namespace App\Pages;

use App\Enums\ContentItemState;
use App\Enums\SitePageKind;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SitePage;
use App\Support\Http\PublicHttpTarget;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Enrol a confirmed publication for measurement without inventing a public-page observation. */
final class RegisterPublishedArticle
{
    public function register(ContentItem $item): ?SitePage
    {
        if ($item->isSocial() || $item->state !== ContentItemState::Published || ! $item->public_url) {
            return null;
        }
        try {
            $item->loadMissing('project');
            if (! $item->project->website_url) {
                return null;
            }
            $target = app(PublicHttpTarget::class)->validate($item->public_url, $item->project->website_url);
            $url = PageUrl::normalize($target->url);

            return app(CurrentProject::class)->run($item->project_id, fn (): ?SitePage => DB::transaction(function () use ($item, $url): ?SitePage {
                Project::query()->whereKey($item->project_id)->lockForUpdate()->firstOrFail();
                $hash = hash('sha256', $url);
                $byItem = SitePage::query()->where('content_item_id', $item->id)->first();
                // Keep paused tracking and established canonical identities intact.
                if ($byItem !== null) {
                    return $byItem;
                }
                $page = SitePage::query()->where('canonical_hash', $hash)->first()
                    ?? SitePage::query()->where('url', $url)->first();
                if ($page !== null) {
                    if (($page->content_item_id !== null && $page->content_item_id !== $item->id)
                        || ($page->locale !== null && $page->locale !== $item->locale)
                        || ($page->canonical_hash !== null && $page->canonical_hash !== $hash)) {
                        return null;
                    }
                    // Associating an existing page does not reactivate it or replace its observations.
                    $page->fill(['content_item_id' => $item->id])->save();

                    return $page;
                }

                return SitePage::query()->create([
                    'url' => $url, 'canonical_url' => $url, 'canonical_hash' => $hash,
                    'locale' => $item->locale, 'content_item_id' => $item->id,
                    'title' => Str::limit($item->title ?: $url, 250, ''),
                    'page_kind' => SitePageKind::Editorial, 'is_article' => true,
                    'tracked_at' => now(), 'published_at' => $item->published_at,
                ]);
            }));
        } catch (Throwable $e) {
            // The publication already happened; the daily measurement sweep retries enrolment.
            Log::warning('Published article measurement enrolment needs retry', ['content_item_id' => $item->id, 'reason' => $e->getMessage()]);

            return null;
        }
    }
}
