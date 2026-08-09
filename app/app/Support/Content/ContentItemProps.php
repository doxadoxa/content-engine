<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Models\ContentItem;

/**
 * One shape for a content unit across every screen that lists one.
 *
 * Shared rather than repeated per controller, because a calendar card, an
 * approvals row and a content row that disagree about what a unit's state is
 * called is the kind of drift nobody notices until a filter stops matching.
 */
final class ContentItemProps
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(ContentItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'title' => $item->title,
            'slug' => $item->slug,
            'locale' => $item->locale,
            'state' => $item->state->value,
            'state_label' => $item->state->label(),
            'is_live' => $item->state->isLive(),
            'type' => $item->type->value,
            'type_label' => $item->type->label(),
            'target_query' => $item->target_query,
            'topic_difficulty' => $item->topic_difficulty,
            'topic_volume' => $item->topic_volume,
            'scheduled_for' => $item->scheduled_for?->toDateString(),
            'published_at' => $item->published_at?->toIso8601String(),
            'public_url' => $item->public_url,
            'needs_original_data' => $item->needs_original_data,
            'locales' => $item->relationLoaded('localeVariants')
                ? $item->localeVariants->pluck('locale')->unique()->sort()->values()->all()
                : [$item->locale],
            'derivatives' => $item->relationLoaded('derivatives') ? $item->derivatives->count() : 0,
        ];
    }
}
