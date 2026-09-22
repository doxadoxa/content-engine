# Purchase record integration · version 1

Create a connected payment source under Performance → Purchases. The page supplies a source-specific endpoint and a one-time connection key. Store the key in the sending server's secret configuration. Never put it in browser code. Use HTTPS outside a local development environment.

## Authentication and delivery

Send `POST /api/purchases/{source_id}` with `Content-Type: application/json` and:

- `X-Avyo-Timestamp`: current Unix time in seconds, within five minutes of receipt.
- `X-Avyo-Signature`: lowercase hexadecimal HMAC-SHA256 of `timestamp + "." + raw_request_body`, using the connection key.

Sign the exact bytes sent. Requests are limited to 64 KiB and 120 requests/minute/IP. Persist an outbox in the same transaction as the source payment change, then send after commit. Retrying an outbox event reuses its event ID, transaction revision and payload, with a fresh signature timestamp. Back off on timeouts, 429 and server errors. A 401 means the source/key/timestamp needs attention; a 409 revision conflict or 422 invalid data needs reconciliation, not a new randomly generated event ID.

## Body

The root object accepts only these fields. Do not send names, addresses, emails, customer identifiers, card details or customer-written text. `evidence` is a ledger reference and a brief operation reason; `items` identify services, not customers.

| Field | Meaning |
| --- | --- |
| `schema_v` | Integer `1`. |
| `event_id` | Stable UUID per outbox event. An identical retry is acknowledged without adding a purchase. Reusing the UUID with changed content returns 409. |
| `transaction_id` | Stable sale identity, 1–200 letters, digits, `_`, `.`, `:`, `-`. A booking/cleaning ID is suitable if it identifies one sale throughout its life. |
| `revision` | Positive integer, monotonically increasing per transaction. Older revisions are recorded as stale and cannot overwrite newer state. A changed state at the same revision returns 409. |
| `occurred_at` | ISO timestamp with timezone of this source change. |
| `purchased_at` | ISO timestamp with timezone when the sale became completed and paid; null for unpaid or partially paid bookings. Refunds preserve the original purchase date. |
| `collection_started_at` | Optional ISO timestamp of the sender's persisted collection start. This does not prove historical completeness. It is recorded separately from the receiver's first receipt and owner verification. |
| `status` | `paid`, `partially_paid`, `unpaid`, `refunded`, `cancelled` or `reconciliation_required`. A booking, button click or quote request is not `paid`. |
| `amount_minor` | Current cumulative legitimate receipts for this sale, in the currency's minor units. For EUR 125.00, send 12500. Corrections may reduce it. |
| `refunded_minor` | Cumulative explicitly documented actual refunds. Ordinary bookkeeping corrections are not refunds. A full refund uses `refunded`; partial refunds retain the sale status. If a later correction makes refunds exceed recorded receipts, retain both true amounts and send `reconciliation_required`. |
| `currency` | Three uppercase currency letters, e.g. EUR. Currency totals remain separate. |
| `landing_url` | Observed, consented landing URL, or null. No inferred path from a service name. |
| `attribution_status` | `attributed`, `unattributed` or `consent_denied`. Attributed requires an actual same-site tracked canonical page match; unknown matches and pre-collection sales remain unattributed. URLs are removed when consent is denied. |
| `is_new_customer` | True only with evidence of a first purchase, false with evidence of an earlier paid purchase, otherwise null. |
| `items` | 1–30 service objects with `item_id`, `item_name` and positive integer `quantity`. |
| `evidence` | Required ledger/operation reference, at most 1000 characters, without personal information. |

`paid` and `refunded` require a positive actual receipt and purchase date. A fully refunded state has refunds equal to receipts. Zero-price visits and unpriced work must not be manufactured into positive sales. `partially_paid` requires a positive receipt and null purchase date; `unpaid` has zero receipts. Source-specific business rules must also verify service completion and the price that defines full payment.

## Acknowledgement and correction

A successful response is JSON with `event_id`, `record_id`, `disposition` (`applied`, `stale` or `duplicate`) and `replayed`. A timeout has an ambiguous outcome: resend the same stored event. The source row is locked while reconciling, so concurrent first receipts cannot create duplicate transactions. Every valid event leaves immutable audit evidence and the current sale state has one row per source/transaction.

Manual reconciliations use a separate manual source and retain the same transaction/revision rules. They have no invented landing-page attribution. Choose one primary source for totals to avoid combining the same sales from multiple systems. Historical records remain readable when a source is paused.

## Verification and limitations

Before treating tracking as verified, compare a received transaction against the real ledger: identity, actual amount/currency, completion, refunds and any correction. Record that reconciliation in Purchases. Also compare aggregate totals regularly; receiving one event does not prove that every sale is delivered. Key rotation clears verification until the new connection is checked.

Purchase reports show current reconciled completed-sale states for each purchase-date window; later refunds/corrections can change an earlier window. A separate recorded-balance view includes all received transactions, including deposits and cancelled sales; without individual payment dates, these balances must not be described as cash flow during the selected window. Inconsistent receipts/refunds remain visible for reconciliation. Neither view establishes incremental sales caused by Avyo. A missing event feed or missing historical tracking is unavailable, not zero. GA4 purchase readings are supplementary and must never be added to authoritative ledger totals. Delayed or non-consented payments can remain valid ledger purchases without attributable GA4 sessions.
