<?php

declare(strict_types=1);

namespace App\Purchases;

use App\Models\PurchaseRecord;
use App\Models\PurchaseSource;
use Carbon\CarbonImmutable;

final class PurchaseReport
{
    /** @return array<string, mixed> */
    public function summarize(?PurchaseSource $source, CarbonImmutable $from, CarbonImmutable $to, ?string $pageId = null): array
    {
        $base = [
            'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
            'source_id' => $source?->id, 'source_name' => $source?->name, 'source_kind' => $source?->kind,
            'tracking_started_at' => $source?->tracking_started_at?->toIso8601String(),
            'verified_at' => $source?->verified_at?->toIso8601String(),
            'last_received_at' => $source?->last_received_at?->toIso8601String(),
            'currencies' => null,
            'recorded_balances' => null,
            'limitations' => ['Observed purchases do not establish additional sales caused by Avyo.', 'Current reconciled states include subsequent corrections and refunds.'],
        ];
        if ($source === null || $source->first_received_at === null) {
            return [...$base, 'status' => 'unavailable'];
        }
        $query = PurchaseRecord::query()->where('purchase_source_id', $source->id)
            ->where('purchased_at', '>=', $from->toIso8601String())->where('purchased_at', '<', $to->toIso8601String());
        if ($pageId !== null) {
            $query->where('site_page_id', $pageId);
        }
        $rows = $query->selectRaw("currency,
            count(*) FILTER (WHERE status IN ('paid', 'refunded')) AS completed_sales,
            count(*) FILTER (WHERE status = 'refunded') AS fully_refunded_sales,
            count(*) FILTER (WHERE status = 'cancelled') AS cancelled_sales,
            coalesce(sum(amount_minor) FILTER (WHERE status IN ('paid', 'refunded')), 0) AS received_minor,
            coalesce(sum(refunded_minor) FILTER (WHERE status IN ('paid', 'refunded')), 0) AS refunded_minor,
            count(*) FILTER (WHERE status IN ('paid', 'refunded') AND attribution_status = 'attributed') AS attributed_sales,
            count(*) FILTER (WHERE status IN ('paid', 'refunded') AND attribution_status != 'attributed') AS unattributed_sales,
            count(*) FILTER (WHERE status IN ('paid', 'refunded') AND is_new_customer = true) AS new_customer_sales,
            count(*) FILTER (WHERE status IN ('paid', 'refunded') AND is_new_customer = false) AS returning_customer_sales,
            count(*) FILTER (WHERE status IN ('paid', 'refunded') AND is_new_customer IS NULL) AS unknown_customer_sales")
            ->groupBy('currency')->get();
        $currencies = $rows->map(static function (PurchaseRecord $row): array {
            $values = ['currency' => $row->currency];
            foreach (['completed_sales', 'fully_refunded_sales', 'cancelled_sales', 'received_minor', 'refunded_minor', 'attributed_sales', 'unattributed_sales', 'new_customer_sales', 'returning_customer_sales', 'unknown_customer_sales'] as $key) {
                $values[$key] = (int) $row->getAttribute($key);
            }
            $values['net_minor'] = $values['received_minor'] - $values['refunded_minor'];

            return $values;
        })->all();
        $limitations = $base['limitations'];
        if ($source->tracking_started_at === null || $source->tracking_started_at->isAfter($from)) {
            $limitations[] = 'Tracking does not cover this entire window; missing history is unavailable.';
        }
        if (! $source->is_enabled) {
            $limitations[] = 'The source is paused; these are its last recorded sale states.';
        }
        if ($source->verified_at === null) {
            $limitations[] = 'These are received records. The owner has not verified them against the payment ledger.';
        }
        if ($source->kind === 'manual') {
            $limitations[] = 'Manually reconciled purchases have no inferred landing-page attribution.';
        }
        // No event is not proof of no sales. The sender does not claim continuous coverage or a daily zero-sales heartbeat.
        $limitations[] = 'The event feed does not prove that every sale was received; reconcile totals with the payment ledger.';

        $balancesQuery = PurchaseRecord::query()->where('purchase_source_id', $source->id);
        if ($pageId !== null) {
            $balancesQuery->where('site_page_id', $pageId);
        }
        // Balances include retained deposits and cancelled sales. Without payment-entry dates these are
        // current balances of all received sale records, never a cash-flow claim about this 28-day window.
        $balances = $balancesQuery->selectRaw("currency, count(*) AS records,
            sum(amount_minor) AS received_minor, sum(refunded_minor) AS refunded_minor,
            count(*) FILTER (WHERE status = 'partially_paid') AS partially_paid,
            count(*) FILTER (WHERE status = 'cancelled') AS cancelled,
            count(*) FILTER (WHERE status = 'reconciliation_required') AS reconciliation_required")
            ->groupBy('currency')->get()->map(static function (PurchaseRecord $row): array {
                $values = ['currency' => $row->currency];
                foreach (['records', 'received_minor', 'refunded_minor', 'partially_paid', 'cancelled', 'reconciliation_required'] as $key) {
                    $values[$key] = (int) $row->getAttribute($key);
                }
                $values['net_minor'] = $values['received_minor'] - $values['refunded_minor'];

                return $values;
            })->all();

        return [...$base, 'status' => $source->verified_at === null ? 'unverified' : 'recorded', 'currencies' => $currencies, 'recorded_balances' => $balances, 'limitations' => $limitations];
    }
}
