<?php

declare(strict_types=1);

namespace App\Support\Http;

final class PublicHttpTarget
{
    public function validate(string $url, ?string $requiredOrigin = null): ValidatedPublicUrl
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafePublicUrl('The address must be a complete HTTP or HTTPS URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafePublicUrl('Only HTTP and HTTPS addresses are allowed.');
        }

        if ((bool) config('security.outbound.require_https') && $scheme !== 'https') {
            throw new UnsafePublicUrl('The address must use HTTPS.');
        }

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafePublicUrl('The address may not contain credentials.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            throw new UnsafePublicUrl('The address contains an invalid host name.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $allowedPorts = array_map('intval', (array) config('security.outbound.allowed_ports', [80, 443]));

        if (! in_array($port, $allowedPorts, true)) {
            throw new UnsafePublicUrl('The address uses a port that is not allowed.');
        }

        $origin = $scheme.'://'.$host.($port === ($scheme === 'https' ? 443 : 80) ? '' : ':'.$port);

        if ($requiredOrigin !== null && ! hash_equals($this->origin($requiredOrigin), $origin)) {
            throw new UnsafePublicUrl('The address must stay on the configured site origin.');
        }

        $addresses = $this->addresses($host);

        if ($addresses === [] && ! (bool) config('security.outbound.allow_unresolved_hosts')) {
            throw new UnsafePublicUrl('The host could not be resolved to a public address.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new UnsafePublicUrl('The address is not reachable on the public internet.');
            }
        }

        return new ValidatedPublicUrl($url, $host, $port, $origin, $addresses);
    }

    public function origin(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafePublicUrl('The address has no valid origin.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.$host.($port === ($scheme === 'https' ? 443 : 80) ? '' : ':'.$port);
    }

    /** @return list<string> */
    private function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        if (function_exists('dns_get_record')) {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);

            if (is_array($records)) {
                foreach ($records as $record) {
                    $address = $record['ip'] ?? $record['ipv6'] ?? null;

                    if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                        $addresses[] = $address;
                    }
                }
            }
        }

        if ($addresses === []) {
            $ipv4 = gethostbynamel($host);

            if (is_array($ipv4)) {
                $addresses = [...$addresses, ...$ipv4];
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
