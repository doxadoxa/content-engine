<?php

declare(strict_types=1);

namespace App\Support\Http;

final readonly class ValidatedPublicUrl
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $origin,
        public array $addresses,
    ) {}

    /** @return array<string, mixed> */
    public function httpOptions(): array
    {
        if ($this->addresses === [] || filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        if (! defined('CURLOPT_RESOLVE')) {
            return [];
        }

        // Resolve once, validate every answer, then make cURL use only those
        // validated addresses. This closes the DNS-rebinding gap between the
        // policy check and the connection itself.
        return [
            'curl' => [
                CURLOPT_RESOLVE => [
                    $this->host.':'.$this->port.':'.implode(',', $this->addresses),
                ],
            ],
        ];
    }
}
