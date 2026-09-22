<?php

declare(strict_types=1);

namespace App\Publishing\Pages;

use App\Models\PagePublicationAttempt;
use App\Models\PagePublicationOperation;
use App\Proposals\Proposals;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PageOperationDispatcher
{
    public function __construct(private readonly PageReceiverClient $client, private readonly EditablePages $sources, private readonly Proposals $proposals) {}

    public function attempt(PagePublicationOperation $operation, bool $reconcileOnly = false, bool $force = false): void
    {
        Cache::lock('native-page-operation:'.$operation->id, 90)->get(function () use ($operation, $reconcileOnly, $force): void {
            $operation = PagePublicationOperation::query()->findOrFail($operation->id);
            if ($operation->committed_at !== null || in_array($operation->status, ['conflict', 'unsupported', 'cancelled'], true)
                || (! $force && $operation->retry_at?->isFuture())) {
                return;
            }
            $sentBefore = $operation->dispatch_started_at !== null;
            try {
                if ($sentBefore || $reconcileOnly) {
                    $response = $this->client->request($operation->channel, $operation->destination, 'GET', '/operations/'.$operation->delivery_id, reconcile: true);
                    $this->log($operation, 'reconcile', $response, $response->successful() ? 'receipt_found' : 'not_confirmed');
                    if ($response->successful()) {
                        $this->commit($operation, (array) $response->json());

                        return;
                    }
                    if ($response->status() !== 404) {
                        $this->unknown($operation, 'The receiver could not establish the original outcome. Reconnect if needed, then reconcile this same identity.');

                        return;
                    }
                    if ($reconcileOnly) {
                        $operation->update(['status' => $sentBefore ? 'outcome_unknown' : 'queued', 'last_error' => 'No committed operation was observed. This is not proof an in-flight operation cannot finish. Retry only the identical authorized operation.']);

                        return;
                    }
                }
                $blocked = DB::transaction(function () use ($operation): ?string {
                    $this->proposals->lockProject();
                    $operation = PagePublicationOperation::query()->lockForUpdate()->findOrFail($operation->id);
                    if ($reason = $this->blocked($operation)) {
                        return $reason;
                    }
                    $operation->update(['status' => 'sending', 'dispatch_started_at' => $operation->dispatch_started_at ?? now(), 'retry_at' => null]);

                    return null;
                });
                if ($blocked !== null) {
                    $operation->update(['status' => $sentBefore ? 'blocked_unresolved' : 'cancelled', 'last_error' => $blocked, 'retry_at' => null]);

                    return;
                }
                // Persist the marker before transport. A lost response always reconciles, never mints a new identity.
                $operation->refresh();
                $response = $this->client->request($operation->channel, $operation->destination, 'POST', '/objects/'.rawurlencode($operation->destination['object_id']).'/operations', $operation->request_body);
                $this->log($operation, 'send', $response, $response->successful() ? 'receipt_received' : 'receiver_response');
                if ($response->successful()) {
                    $this->commit($operation, (array) $response->json());
                } elseif ($response->status() === 409 && in_array($response->json('code'), ['avyo_revision_conflict', 'avyo_recovery_conflict', 'avyo_source_transformed', 'avyo_unrelated_source_changed', 'avyo_capability_changed'], true)) {
                    $this->refuse($operation, 'conflict', 'The CMS source, field ownership or protected content changed. Capture it and review a new revision.');
                } elseif ($response->status() === 422) {
                    $this->refuse($operation, 'unsupported', 'The receiver cannot safely apply this exact change. Use an assisted handoff after reviewing current source.');
                } elseif (in_array($response->status(), [400, 401, 403, 404], true) && ! $sentBefore) {
                    $this->refuse($operation, 'connection_required', 'The receiver refused the connection or request. Reconnect and review the page.');
                } else {
                    $this->unknown($operation, 'The delivery outcome is uncertain. Reconcile the same operation before retrying.');
                }
            } catch (Throwable) {
                $operation->refresh();
                $this->log($operation, $operation->dispatch_started_at === null ? 'preflight' : 'transport', null, 'unavailable');
                if ($operation->dispatch_started_at === null) {
                    $this->refuse($operation, 'connection_required', 'The connection could not be safely reached. No dispatch was started.');
                } else {
                    $this->unknown($operation, 'The response could not be safely confirmed. Reconcile the original operation; its identity and payload are retained.');
                }
            }
        });
    }

    private function blocked(PagePublicationOperation $operation): ?string
    {
        $page = $operation->page;
        if ($page->tracked_at === null || ! PageReceiverClient::same($this->client->destination($operation->channel, $page), $operation->destination)) {
            return 'Tracking or the destination changed. No new write is permitted.';
        }
        if ($operation->kind === 'recovery') {
            return $operation->recoveryOf?->committed_at === null ? 'Reconcile the original operation before recovery.' : null;
        }
        $proposal = $operation->publication->proposal;
        $revision = $operation->publication->revision;
        if ($proposal === null || $revision === null || $proposal->status !== 'approved' || $proposal->approved_revision_id !== $revision->id || $proposal->current_revision_id !== $revision->id) {
            return 'This exact revision no longer has current approval.';
        }

        return $this->proposals->invalidReason($proposal, $revision);
    }

    /** @param array<string,mixed> $receipt */
    private function commit(PagePublicationOperation $operation, array $receipt): void
    {
        $request = json_decode($operation->request_body, true, flags: JSON_THROW_ON_ERROR);
        if (($receipt['schema_v'] ?? null) !== 1 || ($receipt['operation_id'] ?? null) !== $operation->delivery_id
            || ($receipt['object_id'] ?? null) !== $operation->destination['object_id'] || ($receipt['status'] ?? null) !== 'applied'
            || ($receipt['kind'] ?? null) !== $operation->kind || ($receipt['public_verification'] ?? null) !== 'required') {
            throw new UnsupportedPageChange('The receiver result does not identify this exact operation.');
        }
        $before = $this->sources->validate((array) ($receipt['before'] ?? []), $operation->destination);
        $after = $this->sources->validate((array) ($receipt['after'] ?? []), $operation->destination);
        if ($before['revision'] !== $request['expected_revision'] || ! PageReceiverClient::same($before['fields'], $operation->beforeSnapshot->fields ?? []) || $before['revision'] === $after['revision']) {
            throw new UnsupportedPageChange('The receiver result does not match the pinned source revision.');
        }
        $expected = $before['fields'];
        if ($operation->kind === 'recovery') {
            $original = $operation->recoveryOf;
            $originalRequest = json_decode((string) $original?->request_body, true, flags: JSON_THROW_ON_ERROR);
            foreach ($originalRequest['patches'] as $patch) {
                $expected[$patch['field']] = $original?->beforeSnapshot->fields[$patch['field']] ?? '';
            }
        } else {
            foreach ($request['patches'] as $patch) {
                $value = $expected[$patch['field']] ?? '';
                if ($patch['operation'] === 'replace') {
                    $expected[$patch['field']] = str_replace($patch['before'], $patch['after'], $value);
                    if (in_array($patch['field'], ['title', 'description', 'intro'], true)) {
                        $expected[$patch['field']] = $patch['after'];
                    }
                } elseif ($patch['operation'] === 'insert_after') {
                    $expected[$patch['field']] = str_replace($patch['before'], $patch['before']."\n\n".$patch['after'], $value);
                } elseif ($patch['operation'] === 'link') {
                    $link = $patch['field'] === 'body_markdown' ? '['.$patch['before'].']('.$patch['after'].')' : '<a href="'.htmlspecialchars($patch['after'], ENT_QUOTES | ENT_HTML5, 'UTF-8').'">'.$patch['before'].'</a>';
                    $expected[$patch['field']] = str_replace($patch['before'], $link, $value);
                }
            }
        }
        if (array_diff(array_keys($after['fields']), array_keys($expected)) !== [] || array_diff(array_keys($expected), array_keys($after['fields'])) !== []) {
            throw new UnsupportedPageChange('The receiver changed its field contract during publication.');
        }
        foreach ($expected as $field => $value) {
            if ($field !== 'body_text' && ($after['fields'][$field] ?? null) !== $value) {
                throw new UnsupportedPageChange('The receiver transformed an approved field or changed unrelated content.');
            }
        }
        if (isset($before['metadata']['preservation_hash']) && ($after['metadata']['preservation_hash'] ?? null) !== $before['metadata']['preservation_hash']) {
            throw new UnsupportedPageChange('Protected CMS fields changed.');
        }
        DB::transaction(function () use ($operation, $receipt, $before, $after): void {
            $this->proposals->lockProject();
            $operation = PagePublicationOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->committed_at !== null) {
                return;
            }
            $beforeSnapshot = $this->sources->record($operation->page, $before, $operation->destination);
            $afterSnapshot = $this->sources->record($operation->page, $after, $operation->destination);
            $committedAt = isset($receipt['committed_at']) && is_string($receipt['committed_at']) ? Carbon::parse($receipt['committed_at']) : null;
            if ($committedAt === null || $committedAt->greaterThan(now()->addMinutes(5)) || $committedAt->lessThan(($operation->dispatch_started_at ?? $operation->authorized_at)->copy()->subMinutes(5))) {
                throw new UnsupportedPageChange('The receiver commit time is not plausible.');
            }
            $operation->update(['status' => 'applied_unverified', 'before_snapshot_id' => $beforeSnapshot->id, 'after_snapshot_id' => $afterSnapshot->id, 'committed_at' => $committedAt, 'last_error' => null, 'retry_at' => null]);
            if ($operation->kind === 'publish') {
                $operation->publication->update(['status' => 'applied_unverified', 'applied_at' => $committedAt, 'applied_by_name' => $operation->channel->name.' receiver', 'application_note' => 'The receiver confirmed operation '.$operation->delivery_id.'. Public verification is still required.']);
            } else {
                $operation->publication->update(['status' => 'recovery_unverified', 'recovered_at' => $committedAt]);
            }
        });
    }

    private function refuse(PagePublicationOperation $operation, string $status, string $reason): void
    {
        DB::transaction(function () use ($operation, $status, $reason): void {
            $this->proposals->lockProject();
            $operation->update(['status' => $status, 'last_error' => $reason, 'retry_at' => null]);
            if ($operation->kind === 'publish') {
                $proposal = $operation->publication->proposal;
                if ($proposal !== null && $proposal->current_revision_id === $operation->publication->revision_id) {
                    $this->proposals->invalidate($proposal, $reason);
                }
            }
        });
    }

    private function unknown(PagePublicationOperation $operation, string $reason): void
    {
        $operation->refresh();
        $delays = (array) config('publishing.backoff', [60, 300, 1800, 7200, 43200]);
        $delay = (int) $delays[min(count($delays) - 1, (int) floor($operation->attempts / 2))];
        $retryAt = $operation->attempts < 11 ? now()->addSeconds($delay) : null;
        $operation->update(['status' => 'outcome_unknown', 'last_error' => $reason, 'retry_at' => $retryAt]);
        if ($retryAt !== null) {
            DispatchPageOperation::dispatch($operation->id)->onQueue((string) config('publishing.queue', 'pipeline'))->delay($retryAt);
        }
    }

    private function log(PagePublicationOperation $operation, string $action, ?Response $response, string $outcome): void
    {
        DB::transaction(function () use ($operation, $action, $response, $outcome): void {
            $this->proposals->lockProject();
            $operation->refresh();
            $number = $operation->attempts + 1;
            PagePublicationAttempt::query()->create(['operation_id' => $operation->id, 'number' => $number, 'action' => $action, 'http_status' => $response?->status(), 'outcome' => $outcome]);
            $operation->update(['attempts' => $number]);
        });
    }
}
