<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\StrandedDeliveries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $query = WebhookDelivery::query()
            ->with(['channel', 'contentItem'])
            // Dead letters first: everything else in this list is history, and
            // a dead letter is work waiting for a person. A stranded delivery
            // sits with them, because it is the same thing wearing a status
            // that reads as healthy — see {@see StrandedDeliveries}.
            ->orderByRaw(
                "case when status = 'dead_letter' or (status = ? and created_at <= ?) then 0 else 1 end",
                [DeliveryStatus::Pending->value, StrandedDeliveries::cutoff()],
            )
            ->latest()
            ->orderByDesc('id');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $deliveries = $query->paginate(50)->withQueryString();

        $deliveries->through(function (WebhookDelivery $delivery): array {
            return [
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
                'can_replay' => $delivery->status === DeliveryStatus::DeadLetter,
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
                'label' => $case->label(),
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
        // still be sending — replaying it is how a Threads root post gets
        // published twice, which is the failure §9 spends its longest paragraph
        // preventing everywhere else. `isSettled()` is the enum's own name for
        // "nothing is in flight".
        abort_unless(
            $delivery->status->isSettled(),
            409,
            'This delivery has not finished yet. Resending one that is still in flight would send it twice.',
        );

        $replay = $publishers->forDelivery($delivery)->replay($delivery);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Resent as {$replay->delivery_id}.",
        ]);

        return back();
    }
}
