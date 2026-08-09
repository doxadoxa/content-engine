<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\ModelRequest;
use App\Ai\ModelResponse;

/** A metered model door supplied by the pipeline executing the work. */
interface ModelSession
{
    public function send(ModelRequest $request): ModelResponse;
}
