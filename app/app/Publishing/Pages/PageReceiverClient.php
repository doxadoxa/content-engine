<?php

declare(strict_types=1);

namespace App\Publishing\Pages;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\SitePage;
use App\Pages\PageUrl;
use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

final class PageReceiverClient
{
    public function __construct(private readonly PublicHttpTarget $targets) {}

    /** @param array<string,mixed> $left
     * @param  array<string,mixed>  $right
     */
    public static function same(array $left, array $right): bool
    {
        return Arr::sortRecursive($left) === Arr::sortRecursive($right);
    }

    /** @param array<string,mixed> $left
     * @param  array<string,mixed>  $right
     */
    public static function sameDestinationIgnoringCredentials(array $left, array $right): bool
    {
        return self::same(Arr::except($left, ['connection_fingerprint']), Arr::except($right, ['connection_fingerprint']));
    }

    public function fingerprint(Channel $channel): string
    {
        return hash('sha256', json_encode([$channel->type->value, $channel->config['page_receiver_base'] ?? null, $channel->config['username'] ?? null, $channel->getRawOriginal('secret')], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    public function destination(Channel $channel, SitePage $page, ?string $objectId = null, ?string $objectType = null): array
    {
        if ($channel->project_id !== $page->project_id || ! $channel->is_enabled || ! $channel->hasSecret() || ! in_array($channel->type, [ChannelType::WordPress, ChannelType::Webhook], true)) {
            throw new UnsupportedPageChange('Choose an enabled WordPress or compatible custom connection with credentials.');
        }
        $base = rtrim((string) ($channel->config['page_receiver_base'] ?? ''), '/');
        if ($base === '' || parse_url($base, PHP_URL_QUERY) !== null || parse_url($base, PHP_URL_FRAGMENT) !== null) {
            throw new UnsupportedPageChange('Set the complete page receiver base address without a query or fragment.');
        }
        $this->targets->validate($base, $page->project->website_url);
        if (parse_url($base, PHP_URL_SCHEME) !== 'https' && ! $this->targets->isLocalFixture($base)) {
            throw new UnsupportedPageChange('Authenticated page receivers require HTTPS.');
        }
        $id = $objectId ?? $page->cms_object_id;
        $type = $objectType ?? $page->cms_object_type;
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $id) || ! is_string($type) || ! in_array($type, ['page', 'post', 'service', 'article'], true)) {
            throw new UnsupportedPageChange('Choose the exact supported CMS object and language.');
        }
        if ($channel->type === ChannelType::WordPress && (! ctype_digit($id) || ! in_array($type, ['page', 'post'], true) || trim((string) ($channel->config['username'] ?? '')) === '')) {
            throw new UnsupportedPageChange('WordPress needs an account name and an existing numeric page or post ID.');
        }

        return ['type' => $channel->type->value, 'channel_id' => $channel->id, 'base' => $base, 'object_id' => $id, 'object_type' => $type, 'canonical_url' => PageUrl::normalize((string) $page->canonical_url), 'locale' => $page->locale, 'account_name' => $channel->config['username'] ?? null, 'connection_fingerprint' => $this->fingerprint($channel)];
    }

    /** @param array<string,mixed> $destination */
    public function request(Channel $channel, array $destination, string $method, string $path, string $body = '', bool $reconcile = false): Response
    {
        if ($channel->project_id !== $channel->project->id || ! $channel->is_enabled || ! $channel->hasSecret()
            || $channel->id !== $destination['channel_id'] || $channel->type->value !== $destination['type']
            || rtrim((string) ($channel->config['page_receiver_base'] ?? ''), '/') !== $destination['base']
            || ($channel->config['username'] ?? null) !== ($destination['account_name'] ?? null)
            || (! $reconcile && $this->fingerprint($channel) !== $destination['connection_fingerprint'])) {
            throw new UnsupportedPageChange('The connection changed or was disabled. Reconnect and review the destination.');
        }
        $url = rtrim($destination['base'], '/').$path;
        $target = $this->targets->validate($url, $channel->project->website_url);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' && ! $this->targets->isLocalFixture($url)) {
            throw new UnsupportedPageChange('Authenticated page receivers require HTTPS.');
        }
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'User-Agent' => 'Avyo/1.0 (+approved-page-change)'];
        if ($channel->type === ChannelType::WordPress) {
            $headers['Authorization'] = 'Basic '.base64_encode((string) $channel->config['username'].':'.(string) $channel->secret);
        } else {
            $timestamp = (string) now()->timestamp;
            $headers['X-Avyo-Timestamp'] = $timestamp;
            $headers['X-Avyo-Signature'] = hash_hmac('sha256', $timestamp.'.'.strtoupper($method).'.'.(string) parse_url($url, PHP_URL_PATH).'.'.$body, (string) $channel->secret);
        }
        $response = Http::withHeaders($headers)->timeout(20)->retry(0)->withoutRedirecting()
            ->withOptions([...$target->httpOptions(), 'progress' => static function (float $total, float $received): void {
                if ($total > 5_000_000 || $received > 5_000_000) {
                    throw new UnsafePublicUrl('The receiver response exceeded its bounded size.');
                }
            }])->withBody($body, 'application/json')->send(strtoupper($method), $target->url);
        if (strlen($response->body()) > 5_000_000) {
            throw new UnsafePublicUrl('The receiver response exceeded its bounded size.');
        }

        return $response;
    }
}
