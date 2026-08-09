<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Client\Response;

final readonly class PublicHttpResponse
{
    public function __construct(
        public Response $response,
        public string $url,
    ) {}
}
