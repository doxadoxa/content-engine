<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\ChannelType;
use App\Enums\WebhookEvent;
use App\Models\Channel;
use App\Models\WebhookDelivery;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Article delivery shares its durable queue with webhooks; WordPress owns the write receipt. */
class WordPressPublisher extends WebhookPublisher
{
    public function supports(ChannelType $type): bool
    {
        return $type === ChannelType::WordPress;
    }

    protected function transportName(): string
    {
        return 'WordPress';
    }

    protected function endpoint(Channel $channel): string
    {
        $base = rtrim((string) ($channel->config['page_receiver_base'] ?? ''), '/');

        return $base === '' ? '' : $base.'/articles';
    }

    protected function sendRequest(WebhookDelivery $delivery, string $endpoint, string $body, int $timestamp): Response
    {
        $channel = $delivery->channel;
        $channel->loadMissing('project');
        $target = $this->targets->validate($endpoint, $channel->project->website_url);
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' && ! $this->targets->isLocalFixture($endpoint)) {
            throw new UnsafePublicUrl('WordPress publishing requires HTTPS.');
        }
        $username = (string) ($channel->config['username'] ?? '');
        if ($username === '' || ! $channel->hasSecret()) {
            throw new UnsafePublicUrl('Connect a WordPress publishing account first.');
        }

        return Http::withBasicAuth($username, (string) $channel->secret)->acceptJson()
            ->timeout((int) config('publishing.timeout', 15))->retry(0)->withoutRedirecting()
            ->withOptions([...$target->httpOptions(), 'progress' => static function (float $total, float $received): void {
                if ($total > 1_000_000 || $received > 1_000_000) {
                    throw new UnsafePublicUrl('The WordPress response exceeded the supported size.');
                }
            }])->withBody($body, 'application/json')->post($target->url);
    }

    protected function acceptsResponse(WebhookDelivery $delivery, Response $response): bool
    {
        if (! $response->successful() || strlen($response->body()) > 1_000_000) {
            return false;
        }
        $body = $response->json();
        if (! is_array($body) || ($body['delivery_id'] ?? null) !== $delivery->delivery_id || ($body['contract'] ?? null) !== 1) {
            return false;
        }
        if (($delivery->payload_snapshot['event'] ?? '') === WebhookEvent::Ping->value) {
            return ($body['capabilities']['article_publish'] ?? false) === true;
        }
        if (($body['content_id'] ?? null) !== ($delivery->payload_snapshot['content']['id'] ?? null)
            || ($body['status'] ?? null) !== 'published' || ! is_string($body['public_url'] ?? null)
            || ! is_string($body['object_id'] ?? null) || ! ctype_digit($body['object_id'])) {
            return false;
        }
        $content = $delivery->payload_snapshot['content'] ?? [];
        unset($content['published_at']);
        $expectedHash = hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (! is_string($body['content_hash'] ?? null) || ! hash_equals($expectedHash, $body['content_hash'])) {
            return false;
        }
        try {
            $delivery->channel->loadMissing('project');
            $this->targets->validate($body['public_url'], $delivery->channel->project->website_url);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @param array<string,mixed>|null $body */
    protected function succeed(WebhookDelivery $delivery, int $attempt, int $latency, int $status, ?array $body): WebhookDelivery
    {
        $result = parent::succeed($delivery, $attempt, $latency, $status, $body);
        if (($delivery->payload_snapshot['event'] ?? '') === WebhookEvent::Ping->value) {
            $channel = $delivery->channel;
            $channel->forceFill(['config' => [...$channel->config, 'article_publishing_verified' => true]])->save();
        }

        return $result;
    }
}
