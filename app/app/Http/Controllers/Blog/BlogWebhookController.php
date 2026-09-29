<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blog;

use App\Blog\BlogInbox;
use App\Blog\RefusedDelivery;
use App\Blog\StoredPost;
use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyBlogSignature;
use App\Models\BlogDelivery;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where the engine publishes Avyo's own articles.
 *
 * {@see VerifyBlogSignature} has settled who sent this by the time it
 * arrives; what is left is idempotency and doing the thing. The answers are
 * the contract's: 2xx and 409 are "delivered", 5xx is "try again with the same
 * delivery id", and any other 4xx is a dead letter for the operator.
 */
final class BlogWebhookController extends Controller
{
    /**
     * The contract versions this receiver understands (the engine sends
     * `publishing.contract_version`). A body in any other is refused rather
     * than half-read: a later version may mean something this code would
     * store wrongly without noticing.
     */
    public const array CONTRACTS = [1];

    public function __invoke(Request $request, BlogInbox $inbox): JsonResponse
    {
        // The raw body rather than `$request->input()`: it is what the
        // signature covered, and the web group's TrimStrings and
        // ConvertEmptyStringsToNull would otherwise have rewritten the article
        // on its way in.
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || array_is_list($payload)) {
            return $this->refuse('The body must be a JSON object.');
        }

        if (! in_array($payload['contract'] ?? null, self::CONTRACTS, true)) {
            return $this->refuse('Unsupported contract version; this receiver understands '.implode(', ', self::CONTRACTS).'.');
        }

        $deliveryId = $payload['delivery_id'] ?? null;

        if (! is_string($deliveryId) || ! Str::isUuid($deliveryId)) {
            return $this->refuse('The delivery_id must be a UUID.');
        }

        $event = is_string($payload['event'] ?? null) ? WebhookEvent::tryFrom($payload['event']) : null;

        if ($event === null) {
            return $this->refuse('Unknown event.');
        }

        $content = $payload['content'] ?? null;

        if ($event->carriesContent() && ! is_array($content)) {
            return $this->refuse('This event needs a content object.');
        }

        $sentAt = is_string($payload['sent_at'] ?? null) ? $payload['sent_at'] : null;

        try {
            // The claim and the write commit together or not at all. The
            // engine retries a 5xx or a timeout with the *same* delivery id,
            // so a claim that outlived a failed write would answer that retry
            // with 409 — "delivered" — while nothing was stored.
            return DB::transaction(function () use ($inbox, $deliveryId, $event, $content, $sentAt): JsonResponse {
                // `insertOrIgnore` is `ON CONFLICT DO NOTHING`: racing repeats
                // are settled by the unique index with one winner and no
                // exception. Catching a failed insert instead would not work
                // here — on Postgres it aborts the transaction it runs in.
                $claimed = BlogDelivery::query()->insertOrIgnore([
                    'delivery_id' => $deliveryId,
                    'event' => $event->value,
                    'engine_id' => $this->identity($content, 'id', 255),
                    'locale' => $this->identity($content, 'locale', 12),
                    'received_at' => now(),
                ]);

                if ($claimed === 0) {
                    return $this->duplicate($deliveryId);
                }

                /** @var array<mixed> $content */
                return match ($event) {
                    WebhookEvent::Ping => response()->json(['status' => 'ok']),
                    WebhookEvent::Deleted => response()->json([
                        'status' => $inbox->delete($content, $deliveryId, $sentAt) ? 'deleted' : 'stale',
                    ]),
                    WebhookEvent::Published, WebhookEvent::Updated => $this->stored($inbox->store($content, $deliveryId, $sentAt)),
                };
            });
        } catch (RefusedDelivery $refused) {
            return $this->refuse($refused->getMessage());
        }
    }

    /*
     * 200 even when stale: the engine must settle the delivery either way —
     * a retry would be exactly as old — and still learn where the post lives.
     * A post that stays deleted has no address to give.
     */
    private function stored(StoredPost $stored): JsonResponse
    {
        return response()->json(array_filter([
            'status' => $stored->stale ? 'stale' : 'stored',
            // Absolute and on APP_URL: the engine only keeps a public_url on
            // the project's own website origin.
            'public_url' => $stored->post->trashed() ? null : $stored->post->publicUrl(),
        ], static fn (?string $value): bool => $value !== null));
    }

    /*
     * A repeat of a delivery already acted on. Most often the first answer
     * was lost on its way back, and the engine is retrying because it never
     * heard it — so the repeat carries the post's address too, or the unit
     * would never learn it. Scheduled posts count: their URL is already
     * theirs. Deleted ones do not.
     */
    private function duplicate(string $deliveryId): JsonResponse
    {
        $claim = BlogDelivery::query()->where('delivery_id', $deliveryId)->first();

        $post = $claim !== null && $claim->engine_id !== null && $claim->locale !== null
            && in_array($claim->event, [WebhookEvent::Published->value, WebhookEvent::Updated->value], true)
                ? BlogPost::query()->where('engine_id', $claim->engine_id)->where('locale', $claim->locale)->first()
                : null;

        return response()->json(
            $post === null ? ['status' => 'duplicate'] : ['status' => 'duplicate', 'public_url' => $post->publicUrl()],
            409,
        );
    }

    /**
     * One identity field of the content, as the claim row can hold it. The
     * inbox validates it properly; this is only a note for {@see duplicate()}.
     */
    private function identity(mixed $content, string $key, int $max): ?string
    {
        $value = is_array($content) ? ($content[$key] ?? null) : null;

        return is_string($value) && $value !== '' && strlen($value) <= $max ? $value : null;
    }

    private function refuse(string $reason): JsonResponse
    {
        return response()->json(['error' => $reason], 422);
    }
}
