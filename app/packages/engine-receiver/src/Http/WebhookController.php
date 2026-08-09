<?php

declare(strict_types=1);

namespace Persistance\EngineReceiver\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Persistance\EngineReceiver\EngineContent;
use Persistance\EngineReceiver\EngineDelivery;

/**
 * The endpoint. Signature and freshness are already settled by the middleware;
 * what is left is idempotency and doing the thing.
 */
class WebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $event = (string) $request->input('event');
        $deliveryId = (string) $request->input('delivery_id');

        if ($deliveryId === '') {
            return response()->json(['error' => 'No delivery_id.'], 422);
        }

        // The claim, and the idempotency guarantee (§2). `insertOrIgnore` maps
        // to `ON CONFLICT DO NOTHING` and reports how many rows it wrote, so
        // racing deliveries are resolved by the unique index with exactly one
        // winner and no exception.
        //
        // Not a try/catch around a failing insert, which is the obvious way to
        // write this and is wrong on Postgres: a statement that raises inside a
        // transaction aborts the whole transaction, so catching the violation
        // leaves every query after it failing too. A receiving site that wraps
        // its requests in a transaction would find its duplicate handling took
        // the request down with it.
        $claimed = EngineDelivery::query()->insertOrIgnore([
            'delivery_id' => $deliveryId,
            'event' => $event,
            'received_at' => now(),
        ]);

        if ($claimed === 0) {
            return response()->json(['status' => 'duplicate'], 409);
        }

        if ($event === 'ping') {
            return response()->json(['status' => 'ok']);
        }

        /** @var array<string, mixed>|null $content */
        $content = $request->input('content');

        if (! is_array($content) || ! isset($content['id'], $content['locale'])) {
            return response()->json(['error' => 'No content.'], 422);
        }

        if ($event === 'content.deleted') {
            EngineContent::query()
                ->where('engine_id', $content['id'])
                ->where('locale', $content['locale'])
                ->delete();

            return response()->json(['status' => 'deleted']);
        }

        $stored = $this->store($content);

        return response()->json([
            'status' => 'stored',
            // §3: the engine keeps this on the unit, and phase 9 matches search
            // console data against it. A receiver that does not answer with one
            // is connected but not measurable.
            'public_url' => $this->publicUrl($content, $stored),
        ]);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function store(array $content): EngineContent
    {
        return DB::transaction(fn (): EngineContent => EngineContent::query()->updateOrCreate(
            ['engine_id' => $content['id'], 'locale' => $content['locale']],
            [
                'locale_group_id' => $content['locale_group_id'] ?? $content['id'],
                'slug' => $content['slug'] ?? '',
                'type' => $content['type'] ?? null,
                'title' => $content['title'] ?? '',
                'summary' => $content['summary'] ?? null,
                'markdown' => $content['markdown'] ?? null,
                'html' => $content['html'] ?? null,
                'images' => $content['images'] ?? [],
                'json_ld' => $content['json_ld'] ?? [],
                'faq_json_ld' => $content['faq_json_ld'] ?? [],
                'author' => $content['author'] ?? [],
                'internal_links' => $content['internal_links'] ?? [],
                'published_at' => $content['published_at'] ?? null,
            ],
        ));
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function publicUrl(array $content, EngineContent $stored): string
    {
        $resolver = config('engine-receiver.public_url');

        if (is_callable($resolver)) {
            return (string) $resolver($content, $stored);
        }

        return url("/{$content['locale']}/".($content['slug'] ?? ''));
    }
}
