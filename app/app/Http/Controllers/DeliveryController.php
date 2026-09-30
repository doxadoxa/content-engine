<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Models\ArticleSchedule;
use App\Models\WebhookDelivery;
use App\Publishing\Articles\PublicationStatus;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\DeliveryExplanation;
use App\Publishing\StrandedDeliveries;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The delivery log and its replay button (§7, exit criteria 1 and 3).
 *
 * Dead letters first, always. Everything else in this list is history; a dead
 * letter is work waiting for a person, and burying it under two hundred
 * successful deliveries is how it stays buried.
 */
class DeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status');
        [$stranded, $bindings] = StrandedDeliveries::condition();

        $query = WebhookDelivery::query()
            ->with(['channel', 'contentItem'])
            // Dead letters first: everything else in this list is history, and
            // a dead letter is work waiting for a person. A stranded delivery
            // sits with them, because it is the same thing wearing a status
            // that reads as healthy — see {@see StrandedDeliveries}.
            ->orderByRaw(
                "case when status = 'dead_letter' or ({$stranded}) then 0 else 1 end",
                $bindings,
            )
            ->latest()
            ->orderByDesc('id');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $deliveries = $query->paginate(50)->withQueryString();

        $timezone = app(CurrentProject::class)->get()->timezone ?? 'UTC';
        // The schedules pointing at a row on this page, in one query: a row
        // handed back for the owner's approval is not one to try again.
        $schedules = ArticleSchedule::query()
            ->whereIn('delivery_id', collect($deliveries->items())->map(fn (WebhookDelivery $delivery): string => $delivery->getKey())->all())
            ->get()->keyBy('delivery_id');
        $deliveries->through(function (WebhookDelivery $delivery) use ($timezone, $schedules): array {
            $attempt = PublicationStatus::attempt($delivery, $timezone);

            return [
                // The owner's words for the row: what happened, why, and when
                // Avyo tries again. The event and code are kept for the
                // details toggle, where a developer looks for them.
                ...$attempt,
                'is_test' => ($delivery->payload_snapshot['event'] ?? null) === WebhookEvent::Ping->value,
                'id' => $delivery->getKey(),
                'delivery_id' => $delivery->delivery_id,
                'event' => (string) ($delivery->payload_snapshot['event'] ?? ''),
                'status' => $delivery->status->value,
                'status_label' => $delivery->status->label(),
                'response_code' => $delivery->response_code,
                'latency_ms' => $delivery->latency_ms,
                'attempts' => $delivery->attempts,
                'error' => $delivery->error,
                'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
                'created_at' => $delivery->created_at?->toIso8601String(),
                'channel' => $delivery->channel->name,
                'content' => $delivery->contentItem?->title,
                'content_id' => $delivery->content_item_id,
                // Not on one that was taken back: a replay would only be refused.
                'can_replay' => PublicationStatus::canTryAgain($delivery, $schedules->get($delivery->getKey())),
                // A row nothing is going to attempt. `pending` reads as healthy
                // — it is what every delivery looks like for its first second —
                // so without this the one failure with no automatic way out is
                // also the one the screen never mentions. See
                // {@see StrandedDeliveries}; `publish:sweep-stranded` is what
                // clears it, and this is what says so before it runs.
                'is_stranded' => StrandedDeliveries::includes($delivery),
            ];
        });

        return Inertia::render('deliveries/index', [
            'deliveries' => $deliveries,
            'status' => is_string($status) ? $status : null,
            'statuses' => array_map(static fn (DeliveryStatus $case): array => [
                'value' => $case->value,
                'label' => match ($case) {
                    DeliveryStatus::Pending => 'Sending',
                    DeliveryStatus::Delivered => 'Published',
                    DeliveryStatus::Retrying => 'Retrying',
                    DeliveryStatus::DeadLetter => PublicationStatus::FAILED,
                },
            ], DeliveryStatus::cases()),
            'dead_letters' => WebhookDelivery::query()
                ->where('status', DeliveryStatus::DeadLetter)
                ->count(),
            // Counted over the whole log rather than over the page, for the
            // same reason dead letters are: a stranded delivery on page four is
            // still a post that never went out.
            'stranded' => StrandedDeliveries::scope(WebhookDelivery::query())->count(),
        ]);
    }

    /**
     * Replay is common mechanics with transport-specific bytes (§9), so the
     * button asks the registry which transport this row belongs to rather than
     * assuming the one that existed when the log was built.
     */
    public function replay(WebhookDelivery $delivery, ChannelPublisherRegistry $publishers): RedirectResponse
    {
        // The screen only offers the button on a dead letter, and the screen is
        // not the guard. A `pending` or `retrying` row is one somebody may
        // still be sending — replaying it is how an article gets published
        // twice, which is the failure §9 spends its longest paragraph
        // preventing everywhere else. `isSettled()` is the enum's own name for
        // "nothing is in flight".
        //
        // A validation error rather than a bare 409, so the refusal is said
        // beside the button that was pressed instead of on an error page.
        if (! $delivery->status->isSettled()) {
            throw ValidationException::withMessages([
                'delivery' => 'Avyo is still trying to send this. Wait for the result before trying again.',
            ]);
        }

        try {
            $publishers->forDelivery($delivery)->replay($delivery);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['delivery' => self::plain($exception->getMessage())]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Sent again.',
        ]);

        return back();
    }

    /** The delivery guard's refusals, in the words the rest of the product uses. */
    private static function plain(string $message): string
    {
        $text = strtolower($message);

        return match (true) {
            // The replay's own refusals are already written for owners.
            str_starts_with($message, 'This article is being sent right now.'),
            str_starts_with($message, 'Avyo is already trying') => $message,
            str_contains($text, 'no longer matches') => "This article's schedule changed since that attempt. Pick a new date to publish it.",
            str_contains($text, 'changed after this delivery') => 'The article changed since that attempt. Review it, then publish it again.',
            str_contains($text, 'no longer enabled') => "Your website connection isn't working. Check it, then try again.",
            // Everything else in the words every other screen uses, and a
            // plain "couldn't" for a sentence nobody has named.
            default => ($plain = DeliveryExplanation::explain(null, $message)) === null || $plain === DeliveryExplanation::GENERIC
                ? "Avyo couldn't send it again."
                : $plain,
        };
    }
}
