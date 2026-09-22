<?php

declare(strict_types=1);

namespace App\Purchases;

use App\Models\PurchaseEvent;
use App\Models\PurchaseRecord;
use App\Models\PurchaseSource;
use App\Pages\PageUrl;
use App\Pages\TrackedPageIdentity;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseIntake
{
    public function __construct(private readonly CurrentProject $current) {}

    /** @return array{event_id: string, record_id: string|null, disposition: string, replayed: bool} */
    public function receive(PurchaseSource $source, PurchasePayload $payload, ?int $actorId = null): array
    {
        abort_unless($source->project_id === $this->current->id(), 404);

        return DB::transaction(function () use ($source, $payload, $actorId): array {
            // Serialise even the first receipt for a sale; row locks on a not-yet-existing sale do not do that.
            $source = PurchaseSource::query()->lockForUpdate()->findOrFail($source->id);
            abort_unless($source->is_enabled, 409, 'This purchase source is paused.');
            $data = $payload->data;
            $eventHash = $payload->hash();
            $event = PurchaseEvent::query()->where('purchase_source_id', $source->id)->where('event_id', $data['event_id'])->first();
            if ($event !== null) {
                abort_unless(hash_equals($event->payload_hash, $eventHash), 409, 'This event ID has already been used with a different payload.');

                return $this->result($event, true);
            }

            $record = PurchaseRecord::query()->where('purchase_source_id', $source->id)->where('transaction_id', $data['transaction_id'])->first();
            $stateHash = $payload->hash(true);
            $disposition = 'applied';
            if ($record !== null && $record->revision > $data['revision']) {
                $disposition = 'stale';
            } elseif ($record !== null && $record->revision === $data['revision']) {
                abort_unless(hash_equals($record->payload_hash, $stateHash), 409, 'A changed sale needs a higher revision.');
                $disposition = 'duplicate';
            }

            $started = $source->tracking_started_at ?? (empty($data['collection_started_at']) ? now()->toImmutable() : CarbonImmutable::parse($data['collection_started_at']));
            $attribution = $this->attribution($source, $payload, $started);
            if ($disposition === 'applied') {
                $fields = array_intersect_key($data, array_flip([
                    'transaction_id', 'revision', 'status', 'amount_minor', 'refunded_minor', 'currency',
                    'purchased_at', 'occurred_at', 'is_new_customer', 'items', 'evidence',
                ]));
                $fields += ['purchase_source_id' => $source->id, 'payload_hash' => $stateHash, ...$attribution];
                if ($record === null) {
                    $record = PurchaseRecord::query()->create($fields);
                } else {
                    $record->update($fields);
                }
            }

            // Store only the permitted business payload. Denied consent and pre-tracking sales never retain journey URLs.
            $data['landing_url'] = $attribution['landing_url'];
            $data['attribution_status'] = $attribution['attribution_status'];
            $event = PurchaseEvent::query()->create([
                'purchase_source_id' => $source->id, 'purchase_record_id' => $record->id,
                'event_id' => $data['event_id'], 'payload_hash' => $eventHash, 'payload' => $data,
                'disposition' => $disposition, 'recorded_by' => $actorId, 'received_at' => now(),
            ]);
            $source->update([
                'tracking_started_at' => $started, 'first_received_at' => $source->first_received_at ?? now(),
                'last_received_at' => now(),
            ]);

            return $this->result($event, false);
        }, 3);
    }

    /** @return array{site_page_id: string|null, landing_url: string|null, attribution_status: string} */
    private function attribution(PurchaseSource $source, PurchasePayload $payload, CarbonImmutable $started): array
    {
        $data = $payload->data;
        $unattributed = ['site_page_id' => null, 'landing_url' => null, 'attribution_status' => $data['attribution_status'] === 'consent_denied' ? 'consent_denied' : 'unattributed'];
        if ($source->kind === 'manual' || $data['attribution_status'] !== 'attributed' || $data['landing_url'] === null
            || ($data['purchased_at'] !== null && CarbonImmutable::parse($data['purchased_at'])->isBefore($started))) {
            return $unattributed;
        }

        $url = PageUrl::normalize($data['landing_url']);
        $website = $this->current->get()?->website_url;
        if ($website === null || parse_url($url, PHP_URL_HOST) !== parse_url(PageUrl::normalize($website), PHP_URL_HOST)) {
            throw ValidationException::withMessages(['landing_url' => 'An attributed landing page must belong to this project’s website.']);
        }
        $page = app(TrackedPageIdentity::class)->find($url);
        // Captured canonical or alias identity only; a similar path does not prove attribution.
        if ($page !== null) {
            return ['site_page_id' => $page->id, 'landing_url' => $page->canonical_url, 'attribution_status' => 'attributed'];
        }

        return $unattributed;
    }

    /** @return array{event_id: string, record_id: string|null, disposition: string, replayed: bool} */
    private function result(PurchaseEvent $event, bool $replayed): array
    {
        return ['event_id' => $event->event_id, 'record_id' => $event->purchase_record_id, 'disposition' => $event->disposition, 'replayed' => $replayed];
    }
}
