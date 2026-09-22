<?php

declare(strict_types=1);

namespace App\Purchases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validate the sale state rather than treating every received event as another purchase. */
final readonly class PurchasePayload
{
    /** @param array<string, mixed> $data */
    private function __construct(public array $data) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $rules = [
            'schema_v' => ['required', 'integer', 'in:1'],
            'event_id' => ['required', 'uuid'],
            'transaction_id' => ['required', 'string', 'max:200', 'regex:/^[a-zA-Z0-9_.:\-]+$/D'],
            'revision' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'occurred_at' => ['required', 'date', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/D'],
            'purchased_at' => ['present', 'nullable', 'date', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/D'],
            'collection_started_at' => ['nullable', 'date', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/D'],
            'status' => ['required', 'in:paid,partially_paid,unpaid,refunded,cancelled,reconciliation_required'],
            'amount_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'refunded_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'landing_url' => ['present', 'nullable', 'url:http,https', 'max:2000'],
            'attribution_status' => ['required', 'in:attributed,unattributed,consent_denied'],
            'is_new_customer' => ['present', 'nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*' => ['required', 'array:item_id,item_name,quantity'],
            'items.*.item_id' => ['required', 'string', 'max:100'],
            'items.*.item_name' => ['required', 'string', 'max:200'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'evidence' => ['required', 'string', 'max:1000'],
        ];
        if (array_diff(array_keys($input), array_filter(array_keys($rules), static fn (string $key): bool => ! str_contains($key, '.'))) !== []) {
            throw ValidationException::withMessages(['payload' => 'Only the documented purchase fields are accepted. Do not send customer personal information.']);
        }
        $data = Validator::make($input, $rules)->validate();
        $occurred = CarbonImmutable::parse($data['occurred_at']);
        $purchased = $data['purchased_at'] === null ? null : CarbonImmutable::parse($data['purchased_at']);
        $started = empty($data['collection_started_at']) ? null : CarbonImmutable::parse($data['collection_started_at']);
        if ((int) $data['refunded_minor'] > (int) $data['amount_minor'] && $data['status'] !== 'reconciliation_required') {
            throw ValidationException::withMessages(['status' => 'Refunds exceed the corrected receipts. Preserve both amounts using reconciliation_required and investigate the ledger.']);
        }
        if ($occurred->isAfter(now()->addMinutes(5)) || $started?->isAfter(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['occurred_at' => 'Collection and event dates cannot be in the future.']);
        }
        if ($purchased?->isAfter($occurred)) {
            throw ValidationException::withMessages(['purchased_at' => 'The purchase must precede or equal its recorded correction.']);
        }
        if (in_array($data['status'], ['paid', 'refunded'], true) && ($purchased === null || (int) $data['amount_minor'] <= 0)) {
            throw ValidationException::withMessages(['status' => 'A completed sale requires its purchase date and a positive actual receipt.']);
        }
        if ($data['status'] === 'refunded' && (int) $data['refunded_minor'] !== (int) $data['amount_minor']) {
            throw ValidationException::withMessages(['refunded_minor' => 'Use refunded only for a full refund; a partial refund keeps the sale status.']);
        }
        if ($data['status'] === 'unpaid' && ((int) $data['amount_minor'] !== 0 || $purchased !== null)) {
            throw ValidationException::withMessages(['amount_minor' => 'Unpaid bookings have no received payment.']);
        }
        if ($data['status'] === 'partially_paid' && ((int) $data['amount_minor'] <= 0 || $purchased !== null)) {
            throw ValidationException::withMessages(['status' => 'Partial payment requires a positive receipt and is not a completed purchase.']);
        }
        if ($data['attribution_status'] === 'attributed' && $data['landing_url'] === null) {
            throw ValidationException::withMessages(['landing_url' => 'Attributed purchases require the observed landing URL.']);
        }
        if ($data['landing_url'] !== null && (parse_url($data['landing_url'], PHP_URL_USER) !== null || parse_url($data['landing_url'], PHP_URL_PASS) !== null)) {
            throw ValidationException::withMessages(['landing_url' => 'A landing URL cannot contain credentials.']);
        }
        foreach (['schema_v', 'revision', 'amount_minor', 'refunded_minor'] as $key) {
            $data[$key] = (int) $data[$key];
        }
        $data['is_new_customer'] = $data['is_new_customer'] === null ? null : (bool) $data['is_new_customer'];
        $data['occurred_at'] = $occurred->utc()->toIso8601String();
        $data['purchased_at'] = $purchased?->utc()->toIso8601String();
        $data['collection_started_at'] = $started?->utc()->toIso8601String();

        return new self($data);
    }

    public function hash(bool $stateOnly = false): string
    {
        $data = $this->data;
        if ($stateOnly) {
            unset($data['event_id']);
        }

        return hash('sha256', json_encode(self::ordered($data), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function ordered(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::ordered($item);
            }
        }

        return $value;
    }
}
