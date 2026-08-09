<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use App\Integrations\Exceptions\GoogleUnavailable;
use App\Models\ProjectIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What this Google account can actually show us.
 *
 * The operator picks from their real properties rather than typing an
 * identifier: Search Console names a site `sc-domain:example.com` or
 * `https://example.com/` — trailing slash included — and GA4 wants a numeric
 * property id nobody has memorised. Every one of those is a way to store
 * something that looks right and returns nothing.
 */
class GoogleProperties
{
    private const string SEARCH_CONSOLE_SITES = 'https://www.googleapis.com/webmasters/v3/sites';

    private const string ANALYTICS_SUMMARIES = 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries';

    public function __construct(private readonly GoogleConnection $connection) {}

    /**
     * Search Console properties, verified ones only.
     *
     * An unverified site answers every performance query with nothing, so
     * offering it would be offering a choice that silently does not work.
     *
     * @return list<array{value: string, label: string}>
     */
    public function searchConsoleSites(ProjectIntegration $integration): array
    {
        if (! $integration->grants(ProjectIntegration::SCOPE_SEARCH_CONSOLE)) {
            return [];
        }

        $body = $this->get($integration, self::SEARCH_CONSOLE_SITES);

        $entries = $body['siteEntry'] ?? [];

        if (! is_array($entries)) {
            return [];
        }

        $sites = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $url = $entry['siteUrl'] ?? null;
            $permission = (string) ($entry['permissionLevel'] ?? '');

            if (! is_string($url) || $url === '' || $permission === 'siteUnverifiedUser') {
                continue;
            }

            $sites[] = ['value' => $url, 'label' => $this->readable($url)];
        }

        return $sites;
    }

    /**
     * GA4 properties, across every account this login can see.
     *
     * @return list<array{value: string, label: string}>
     */
    public function analyticsProperties(ProjectIntegration $integration): array
    {
        if (! $integration->grants(ProjectIntegration::SCOPE_ANALYTICS)) {
            return [];
        }

        $properties = [];

        foreach ($this->allSummaries($integration) as $account) {
            $accountName = (string) ($account['displayName'] ?? '');
            $list = $account['propertySummaries'] ?? [];

            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $property) {
                if (! is_array($property)) {
                    continue;
                }

                // `properties/123456789` — the resource name, which is what
                // every Data API call wants. Storing the bare number would mean
                // reassembling it at each call site.
                $name = $property['property'] ?? null;

                if (! is_string($name) || $name === '') {
                    continue;
                }

                $label = (string) ($property['displayName'] ?? $name);

                $properties[] = [
                    'value' => $name,
                    'label' => $accountName === '' ? $label : "{$accountName} · {$label}",
                ];
            }
        }

        return $properties;
    }

    /**
     * The property that most likely belongs to this site.
     *
     * A guess, offered as a preselection and not as a decision: it saves the
     * common case a scroll through forty properties, and being wrong costs a
     * click rather than a month of data from somebody else's website.
     *
     * @param  list<array{value: string, label: string}>  $options
     */
    public function matching(array $options, ?string $websiteUrl): ?string
    {
        $host = $websiteUrl === null ? null : parse_url($websiteUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        // replaceStart, not after(): `after` would turn `notwww.example.com`
        // into `example.com` and match a property for a different site.
        $host = Str::replaceStart('www.', '', Str::lower($host));

        foreach ($options as $option) {
            $candidate = Str::lower($option['value'].' '.$option['label']);

            if (str_contains($candidate, $host)) {
                return $option['value'];
            }
        }

        return null;
    }

    /**
     * Every page of account summaries.
     *
     * `pageSize` caps at 200 and an agency login can see more than that. A
     * truncated list is worse than a slow one: the operator scrolls, does not
     * find their property, and concludes the feature does not support it.
     *
     * @return list<array<string, mixed>>
     */
    private function allSummaries(ProjectIntegration $integration): array
    {
        $summaries = [];
        $pageToken = null;

        // Bounded so a malformed or looping `nextPageToken` cannot spin here
        // forever. Ten pages is two thousand properties.
        for ($page = 0; $page < 10; $page++) {
            $body = $this->get($integration, self::ANALYTICS_SUMMARIES, array_filter([
                'pageSize' => 200,
                'pageToken' => $pageToken,
            ]));

            foreach ($body['accountSummaries'] ?? [] as $account) {
                if (is_array($account)) {
                    $summaries[] = $account;
                }
            }

            $pageToken = $body['nextPageToken'] ?? null;

            if (! is_string($pageToken) || $pageToken === '') {
                break;
            }
        }

        return $summaries;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(ProjectIntegration $integration, string $url, array $query = []): array
    {
        $token = $this->connection->accessToken($integration);

        if ($token === null) {
            return [];
        }

        try {
            $response = Http::withToken($token)->timeout(20)->get($url, $query);
        } catch (ConnectionException $e) {
            throw new GoogleUnavailable("Google did not answer: {$e->getMessage()}");
        }

        if ($response->failed()) {
            $this->handleFailure($integration, $response);

            return [];
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    private function handleFailure(ProjectIntegration $integration, Response $response): void
    {
        // 401 after we just refreshed means the grant itself is gone. That is
        // the whole connection, and a human has to reconnect.
        if ($response->status() === 401) {
            $integration->markBroken('Google refused the connection. Reconnect to grant access again.');

            return;
        }

        // 403 is narrower: this account cannot see this API or this property.
        // Marking the connection broken on it would take Search Console down
        // because Analytics said no, so the list degrades to empty instead.
        if ($response->status() === 403) {
            Log::warning('Google refused a listing request', [
                'url' => $response->effectiveUri()?->getPath(),
                'status' => 403,
            ]);

            return;
        }

        throw new GoogleUnavailable("Google answered {$response->status()}.");
    }

    /** `sc-domain:example.com` and `https://example.com/` both read as a site. */
    private function readable(string $siteUrl): string
    {
        if (str_starts_with($siteUrl, 'sc-domain:')) {
            return Str::after($siteUrl, 'sc-domain:').' (domain)';
        }

        return rtrim($siteUrl, '/');
    }
}
