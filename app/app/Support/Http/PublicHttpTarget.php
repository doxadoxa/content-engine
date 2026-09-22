<?php

declare(strict_types=1);

namespace App\Support\Http;

final class PublicHttpTarget
{
    /** @var array{origin: string, addresses: list<string>}|null */
    private ?array $localFixture = null;

    /**
     * An integration command may use a named isolated local website fixture.
     * This instance is never registered by configuration, web routes or jobs.
     * The only exception is the named fixture origin, with a pinned Docker-host
     * address; redirects and all other destinations keep the normal policy.
     */
    public static function forLocalFixture(string $name): self
    {
        if (! app()->runningInConsole() || ! app()->environment(['local', 'testing'])) {
            throw new UnsafePublicUrl('Local fixture access is restricted to local integration commands.');
        }
        $origin = match ($name) {
            'wordpress' => 'http://localhost:8093',
            'cleaningpoint' => 'http://localhost:8094',
            default => throw new UnsafePublicUrl('Unknown isolated integration fixture.'),
        };
        $addresses = gethostbynamel('host.docker.internal') ?: ['127.0.0.1'];
        if (array_filter($addresses, static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) !== []) {
            throw new UnsafePublicUrl('The Docker fixture address could not be resolved.');
        }
        $instance = new self;
        $instance->localFixture = ['origin' => $origin, 'addresses' => array_values(array_unique($addresses))];

        return $instance;
    }

    public function isLocalFixture(string $url): bool
    {
        if ($this->localFixture === null || ! app()->runningInConsole() || ! app()->environment(['local', 'testing'])) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && ! isset($parts['user']) && ! isset($parts['pass'])
            && isset($parts['scheme'], $parts['host'])
            && hash_equals($this->localFixture['origin'], $this->origin($url));
    }

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

        $fixture = $this->isLocalFixture($url);
        if ((bool) config('security.outbound.require_https') && $scheme !== 'https' && ! $fixture) {
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

        if (! in_array($port, $allowedPorts, true) && ! $fixture) {
            throw new UnsafePublicUrl('The address uses a port that is not allowed.');
        }

        $origin = $scheme.'://'.$host.($port === ($scheme === 'https' ? 443 : 80) ? '' : ':'.$port);

        if ($requiredOrigin !== null && ! hash_equals($this->origin($requiredOrigin), $origin)) {
            throw new UnsafePublicUrl('The address must stay on the configured site origin.');
        }

        if ($fixture && $this->localFixture !== null) {
            return new ValidatedPublicUrl($url, $host, $port, $origin, $this->localFixture['addresses']);
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
            // Silenced deliberately, and only here. A resolver that answers
            // SERVFAIL — a nameserver blinking, a rate limit, a network hiccup
            // — makes this emit a warning, and under an error handler that
            // promotes warnings that warning becomes a thrown exception. Which
            // means a transient blip skipped both of the two careful things
            // below it: the `gethostbynamel` fallback that would probably have
            // succeeded, and the explicit refusal that turns an unresolvable
            // host into "the host could not be resolved to a public address"
            // instead of a stack trace. CI caught it as a flake in the
            // onboarding wizard; in production it is a 500 on somebody's
            // perfectly good URL.
            //
            // Nothing about the security posture changes. A failed lookup
            // contributes no addresses, an empty address list is refused above
            // unless a deployment has opted out, and every address that does
            // come back is still checked against the private ranges.
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);

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
