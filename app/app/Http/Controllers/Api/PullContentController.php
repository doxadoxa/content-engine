<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ContentItemState;
use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Publishing\PullCursor;
use App\Publishing\WebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The pull API (§9.5), for SSG sites that cannot take a webhook.
 *
 * The same payload shape the webhook sends, so a site can move between the two
 * without changing how it reads content — one contract, two transports.
 *
 * Versioned by `updated_at` rather than paged by number: a static site rebuilds
 * by asking "what has changed since my last build", and page 3 of a list that
 * grew while you were reading it is not an answer to that.
 */
class PullContentController extends Controller
{
    private const int MAX_PER_PAGE = 100;

    public function __construct(private readonly PullCursor $cursors) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:4096'],
            'since' => ['prohibited'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $cursor = $validated['cursor'] ?? null;
        $position = is_string($cursor) ? $this->cursors->decode($cursor) : null;

        $query = ContentItem::query()
            ->roots()
            ->whereIn('state', [ContentItemState::Published->value, ContentItemState::Refreshing->value])
            // Ordered by the column the cursor moves along, so a page boundary
            // cannot skip a row that was edited mid-read.
            ->orderBy('updated_at')
            ->orderBy('id');

        if ($position !== null) {
            $query->where(function ($query) use ($position): void {
                $query->where('updated_at', '>', $position['updated_at'])
                    ->orWhere(function ($query) use ($position): void {
                        $query->where('updated_at', $position['updated_at'])
                            ->where('id', '>', $position['id']);
                    });
            });
        }

        $units = $query->limit($perPage + 1)->get();

        // One more than asked for, so "is there another page" is a fact rather
        // than a second count query.
        $hasMore = $units->count() > $perPage;
        $page = $units->take($perPage);

        return response()->json([
            'contract' => (int) config('publishing.contract_version', 1),
            'data' => $page->map(fn (ContentItem $unit): array => WebhookPayload::for(
                $unit,
                WebhookEvent::Published,
                'pull',
            )['content'])->values()->all(),
            'meta' => [
                'per_page' => $perPage,
                'has_more' => $hasMore,
                // Preserve the input cursor when there were no changes, so a
                // consumer never falls back to the beginning of the corpus.
                'next_cursor' => $page->last() === null
                    ? $cursor
                    : $this->cursors->encode($page->last()),
            ],
        ]);
    }
}
