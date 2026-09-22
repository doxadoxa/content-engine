<?php

declare(strict_types=1);

namespace App\Publishing\Pages;

use App\Models\Channel;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\SitePage;
use App\Pages\PageUrl;
use App\Pages\TrackedPages;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class EditablePages
{
    public function __construct(private readonly PageReceiverClient $client, private readonly TrackedPages $public, private readonly CurrentProject $current) {}

    public function bind(SitePage $page, Channel $channel, string $objectId, string $objectType): PageSnapshot
    {
        $destination = $this->client->destination($channel, $page, $objectId, $objectType);
        $source = $this->read($channel, $destination);
        $this->public->capture($this->current->get(), $page);

        return DB::transaction(function () use ($page, $channel, $destination, $source): PageSnapshot {
            Project::query()->whereKey($this->current->id())->lockForUpdate()->firstOrFail();
            $fresh = SitePage::query()->lockForUpdate()->findOrFail($page->id);
            abort_unless($fresh->tracked_at !== null && $fresh->canonical_url === $destination['canonical_url'] && $fresh->locale === $destination['locale'], 409, 'The tracked page changed during binding.');
            $channel = Channel::query()->findOrFail($channel->id);
            abort_unless($channel->is_enabled && $this->client->fingerprint($channel) === $destination['connection_fingerprint'], 409, 'The connection changed during the source read.');
            $fresh->update(['channel_id' => $channel->id, 'cms_object_id' => $destination['object_id'], 'cms_object_type' => $destination['object_type']]);
            $channel->update(['verified_at' => now()]);

            return $this->record($fresh, $source, $destination);
        });
    }

    public function capture(SitePage $page): PageSnapshot
    {
        $channel = $page->channel;
        if ($channel === null) {
            throw new UnsupportedPageChange('Bind this page to its exact CMS object first.');
        }
        $destination = $this->client->destination($channel, $page);
        $source = $this->read($channel, $destination);

        return DB::transaction(function () use ($page, $channel, $destination, $source): PageSnapshot {
            Project::query()->whereKey($this->current->id())->lockForUpdate()->firstOrFail();
            $fresh = SitePage::query()->lockForUpdate()->findOrFail($page->id);
            abort_unless($fresh->tracked_at !== null && PageReceiverClient::same($this->client->destination($channel->fresh(), $fresh), $destination), 409, 'Tracking, connection or object identity changed during the read.');

            return $this->record($fresh, $source, $destination);
        });
    }

    /** @param array<string,mixed> $destination
     * @return array<string,mixed>
     */
    public function read(Channel $channel, array $destination): array
    {
        $response = $this->client->request($channel, $destination, 'GET', '/objects/'.rawurlencode($destination['object_id']));
        if (! $response->successful()) {
            throw ValidationException::withMessages(['cms' => match ($response->status()) {
                401, 403 => 'The CMS refused these credentials. Reconnect or use an assisted handoff.',
                404 => 'The receiver or exact object was not found. Check the plugin, address and object ID.',
                409, 422 => 'This object cannot currently be edited through this receiver. Use an assisted handoff.',
                default => 'The editable source is unavailable. No source revision was assumed.',
            }]);
        }

        return $this->validate((array) $response->json(), $destination);
    }

    /** @param array<string,mixed> $source
     * @param  array<string,mixed>  $destination
     * @return array<string,mixed>
     */
    public function validate(array $source, array $destination): array
    {
        Validator::make($source, [
            'schema_v' => ['required', 'integer', 'in:1'], 'object_id' => ['required', 'string'], 'object_type' => ['required', 'string'],
            'public_url' => ['required', 'url:http,https'], 'revision' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'fields' => ['required', 'array'], 'fields.*' => ['string'], 'editable_fields' => ['present', 'array'], 'editable_fields.*' => ['string'],
            'metadata' => ['present', 'array'], 'capabilities' => ['required', 'array'],
            'capabilities.idempotency' => ['required', 'accepted'], 'capabilities.reconciliation' => ['required', 'accepted'], 'capabilities.recovery' => ['required', 'accepted'],
        ])->validate();
        if ($source['object_id'] !== $destination['object_id'] || $source['object_type'] !== $destination['object_type']
            || PageUrl::normalize($source['public_url']) !== $destination['canonical_url']
            || ($destination['type'] === 'webhook' && ($source['locale'] ?? null) !== $destination['locale'])) {
            throw new UnsupportedPageChange('The receiver object does not match this exact tracked page and language.');
        }
        if (strlen(json_encode($source['fields'], JSON_THROW_ON_ERROR)) > 1_200_000) {
            throw new UnsupportedPageChange('The editable source exceeds the supported size.');
        }

        return $source;
    }

    /** @param array<string,mixed> $source
     * @param  array<string,mixed>  $destination
     */
    public function record(SitePage $page, array $source, array $destination): PageSnapshot
    {
        $source = $this->validate($source, $destination);

        return PageSnapshot::query()->create([
            'site_page_id' => $page->id, 'source_kind' => $destination['type'], 'source_url' => $destination['canonical_url'],
            'captured_at' => now(), 'revision' => $source['revision'], 'content_hash' => hash('sha256', json_encode($source['fields'], JSON_THROW_ON_ERROR)),
            'fields' => $source['fields'], 'editable_fields' => $source['editable_fields'],
            'metadata' => [...$source['metadata'], 'destination' => $destination, 'canonical_url' => $destination['canonical_url'], 'locale' => $destination['locale'],
                'capabilities' => $source['capabilities'], 'verification' => $source['verification'] ?? [], 'content_format' => $source['content_format'] ?? ($destination['type'] === 'wordpress' ? 'wordpress_html' : null), 'core_revision_id' => $source['core_revision_id'] ?? null],
        ]);
    }
}
