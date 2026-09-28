<?php

declare(strict_types=1);

namespace App\Media;

use App\Content\ArticleBusinessFacts;
use App\Enums\AssetRole;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\PipelineRunStatus;
use App\Enums\WebhookEvent;
use App\Models\Asset;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use App\Pipelines\Steps\Generation\IllustrateDraft;
use App\Publishing\PublishToChannels;
use App\Support\Content\SafeMarkdown;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Moving a project's pictures onto the configured media disk.
 *
 * Every asset row records the disk it was written to, and changing
 * `MEDIA_DISK` only changes where the *next* picture goes. A project that ran
 * on `public` before it had a bucket is left with rows pointing at a
 * container's own storage directory — which the web container cannot see, and
 * which did not survive the next deploy anyway. Nothing in the engine redraws
 * a picture a unit already has: {@see HeroImage::for()} and
 * {@see HeroImage::inline()} both return the existing row first, so a rewrite
 * hands the same dead link straight back.
 *
 * The unit of work is the **file**, not the row. Locales of one unit share one
 * file through {@see HeroImage::borrow()}, so relocating per row would pay to
 * draw the same photograph once per language; here each file is moved once
 * and every row naming it follows.
 *
 * A file that still exists on its old disk is copied, which costs nothing.
 * Only a file that is gone is drawn again, from the same prompt the original
 * was drawn from.
 *
 * The article body carries the picture URLs inline, and that body is what the
 * business-fact check hashed ({@see ArticleBusinessFacts::refusal()}). Swapping
 * a URL is not a change to anything that was checked, so a seal that matched
 * before the swap is moved to the new body. One that did not match is left
 * alone: this must not bless an article that was already waiting for a human.
 */
class RelocateMedia
{
    public function __construct(
        private readonly HeroImage $hero,
        private readonly SafeMarkdown $markdown,
        private readonly ArticleBusinessFacts $facts,
        private readonly PublishToChannels $channels,
    ) {}

    /**
     * The current project's pictures that are not on the media disk, one group
     * per file, heroes first.
     *
     * Heroes first so that a section picture drawn again can be handed its
     * article's new hero as a reference, which is what keeps a set looking like
     * one article.
     *
     * Narrowing to one unit still takes every row sharing that unit's files, so
     * a sibling locale is not left pointing at the old copy.
     *
     * @return list<Collection<int, Asset>>
     */
    public function stale(?string $unitId = null): array
    {
        $keys = $this->staleQuery()
            ->when($unitId, fn ($q, $id) => $q->where('content_item_id', $id))
            ->get(['disk', 'path'])
            ->map(static fn (Asset $asset): string => $asset->disk."\0".$asset->path)
            ->unique();

        if ($keys->isEmpty()) {
            return [];
        }

        $rows = $this->staleQuery()
            ->whereIn('path', $keys->map(static fn (string $key): string => explode("\0", $key, 2)[1])->all())
            ->with('contentItem')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Asset $asset): bool => $keys->contains($asset->disk."\0".$asset->path));

        $groups = [];

        foreach ($rows as $asset) {
            $groups[$asset->disk."\0".$asset->path][] = $asset;
        }

        $groups = array_map(static fn (array $rows): Collection => new Collection($rows), array_values($groups));

        // `usort` is stable, so within each role the rows keep the order they
        // were made in.
        usort($groups, static fn (Collection $a, Collection $b): int => $b->contains('role', AssetRole::Hero) <=> $a->contains('role', AssetRole::Hero));

        return $groups;
    }

    /**
     * Whether the file behind these rows can still be read from where it was written.
     *
     * @param  Collection<int, Asset>  $rows
     */
    public function copyable(Collection $rows): bool
    {
        return $this->bytes($rows->first()) !== null;
    }

    /**
     * Why these rows must be left for a later run, or null if they may move.
     *
     * A pipeline in flight on any unit holding this file is about to write that
     * unit's body, and {@see IllustrateDraft}
     * fails terminally — correctly — when the body it read has changed under it.
     *
     * @param  Collection<int, Asset>  $rows
     */
    public function busy(Collection $rows): ?string
    {
        $units = $rows->pluck('content_item_id')->unique()->values()->all();

        $running = PipelineRun::query()
            ->whereIn('content_item_id', $units)
            ->whereIn('status', [PipelineRunStatus::Pending, PipelineRunStatus::Running])
            ->exists();

        if ($running) {
            return 'A pipeline is running on this article.';
        }

        if ($rows->contains(static fn (Asset $asset): bool => $asset->contentItem?->state === ContentItemState::Refreshing)) {
            return 'This article is being refreshed.';
        }

        return null;
    }

    /**
     * Put one file on the media disk and point every row, and every body that
     * shows it, at the new copy.
     *
     * The file is written before anything in the database changes, and the rows
     * and bodies change together, so a failure at any point leaves the article
     * either as it was or finished — never with rows naming a file that is not
     * there, which is what {@see MediaDisk} exists to prevent.
     *
     * A published unit whose last stale file this was is queued for redelivery
     * in the same transaction. Queued afterwards, a run that died in between
     * would leave the unit fixed here and broken on its channels, and no later
     * run could tell: {@see stale()} no longer finds it. Waiting for the last
     * file means one update per article rather than one per picture.
     *
     * @param  Collection<int, Asset>  $rows
     * @return array{redrawn: bool, cost: int, queued: int}
     */
    public function relocate(Collection $rows): array
    {
        /** @var Asset $original */
        $original = $rows->first();
        $bytes = $this->bytes($original);
        $redrawn = $bytes === null;
        $cost = 0;

        if ($bytes !== null) {
            MediaDisk::put($original->path, $bytes);
            $moved = [
                'disk' => MediaDisk::name(),
                'path' => $original->path,
                'width' => $original->width,
                'height' => $original->height,
            ];
        } else {
            /** @var ContentItem $unit */
            $unit = $original->contentItem;
            $image = $this->hero->redraw($original, $unit, $this->references($original, $unit));
            $moved = [
                'disk' => $image->disk,
                'path' => $image->path,
                'width' => $image->width,
                'height' => $image->height,
            ];
            $cost = $image->costMicros;
        }

        $url = Storage::disk($moved['disk'])->url($moved['path']);
        $units = array_values(array_unique(array_map(
            static fn (Asset $asset): string => $asset->content_item_id,
            $rows->all(),
        )));

        $queued = DB::transaction(function () use ($rows, $original, $moved, $url, $units): int {
            Asset::query()->whereKey($rows->pluck('id')->all())->update($moved);

            $queued = 0;

            foreach ($units as $unitId) {
                $unit = $this->rewriteBody($unitId, $original->path, $url);

                if ($unit !== null && ! $this->hasStale($unitId)) {
                    $queued += $this->redeliver($unit);
                }
            }

            return $queued;
        });

        return ['redrawn' => $redrawn, 'cost' => $cost, 'queued' => $queued];
    }

    /** Whether the unit still shows a picture that is not on the media disk. */
    private function hasStale(string $unitId): bool
    {
        return $this->staleQuery()->where('content_item_id', $unitId)->exists();
    }

    /**
     * The pictures a unit ships that are not on the media disk. Variants are
     * candidates nobody chose and superseded rows are history; neither is shown.
     *
     * @return Builder<Asset>
     */
    private function staleQuery(): Builder
    {
        return Asset::query()
            ->whereIn('role', [AssetRole::Hero, AssetRole::Inline])
            ->whereNull('superseded_at')
            ->where('disk', '!=', MediaDisk::name());
    }

    /**
     * Send a published unit's new pictures to every channel that has its old
     * ones.
     *
     * Only channels that already hold the article: a relocation is a correction
     * to something that is live, not an occasion to publish it somewhere new.
     * The publisher sees a delivered revision and sends `content.updated`; the
     * delivery row is written in the caller's transaction and its job is
     * dispatched after commit.
     *
     * @return int how many deliveries were queued
     */
    private function redeliver(ContentItem $unit): int
    {
        if ($unit->state !== ContentItemState::Published) {
            return 0;
        }

        $channels = Channel::query()
            ->whereIn('id', WebhookDelivery::query()
                ->where('content_item_id', $unit->getKey())
                ->where('status', DeliveryStatus::Delivered->value)
                ->whereIn('payload_snapshot->event', [
                    WebhookEvent::Published->value,
                    WebhookEvent::Updated->value,
                ])
                ->select('channel_id'))
            ->get();

        $queued = 0;

        foreach ($channels as $channel) {
            if ($this->channels->publishToSelected($unit, $channel) !== null) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Swap the picture's URL in one unit's body, and move its seal with it.
     *
     * Matched on the file's path rather than on its old URL. The URL is built
     * from `APP_URL` at the time it was written, and a deployment that changed
     * its address since would leave a URL this could not reconstruct; the path
     * is random and does not change.
     *
     * @return ContentItem|null the unit as saved, still locked
     */
    private function rewriteBody(string $unitId, string $path, string $url): ?ContentItem
    {
        $unit = ContentItem::query()->whereKey($unitId)->lockForUpdate()->first();

        if ($unit === null) {
            return null;
        }

        $before = (string) $unit->body_markdown;
        $after = preg_replace(
            '/(!\[[^\]]*\]\()[^)\s]*'.preg_quote('/'.$path, '/').'(\))/u',
            '${1}'.str_replace(['\\', '$'], ['\\\\', '\\$'], $url).'${2}',
            $before,
        );

        if ($after === null || $after === $before) {
            // Nothing in the body names it — a hero, or a picture the body has
            // since dropped. Still touch the row, so the pull API, which pages
            // by `updated_at`, hands the new image URL to a static site.
            $unit->touch();

            return $unit;
        }

        $sealed = $this->sealedHash($unit);
        $matched = $sealed !== null && hash_equals($sealed, $this->facts->bodyHash($unit));

        $unit->forceFill([
            'body_markdown' => $after,
            'body_html' => $this->markdown->render($after),
        ])->save();

        if ($matched) {
            DB::table('article_business_contexts')
                ->where('project_id', $unit->project_id)
                ->where('content_item_id', $unit->getKey())
                ->orderByDesc('id')
                ->limit(1)
                ->update(['body_hash' => $this->facts->bodyHash($unit)]);
        }

        return $unit;
    }

    /** The hash the latest business-fact check sealed, as {@see ArticleBusinessFacts::refusal()} reads it. */
    private function sealedHash(ContentItem $unit): ?string
    {
        $hash = DB::table('article_business_contexts')
            ->where('project_id', $unit->project_id)
            ->where('content_item_id', $unit->getKey())
            ->orderByDesc('id')
            ->value('body_hash');

        return is_string($hash) ? $hash : null;
    }

    /**
     * The file's bytes from the disk it was written to, or null when they are
     * gone — including when that disk is no longer configured at all.
     */
    private function bytes(Asset $asset): ?string
    {
        try {
            $disk = Storage::disk($asset->disk);

            return $disk->exists($asset->path) ? $disk->get($asset->path) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The article's hero as a reference for one of its section pictures, when
     * the hero is already on the media disk and the provider could fetch it.
     *
     * @return list<string>
     */
    private function references(Asset $asset, ContentItem $unit): array
    {
        if ($asset->role !== AssetRole::Inline) {
            return [];
        }

        $hero = $unit->assets()->where('role', AssetRole::Hero)->first();

        if ($hero === null || $hero->disk !== MediaDisk::name()) {
            return [];
        }

        $url = $hero->url();

        return PublicUrl::isFetchable($url) ? [$url] : [];
    }
}
