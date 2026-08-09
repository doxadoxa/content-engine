<?php

declare(strict_types=1);

namespace App\Media;

/**
 * A picture that now exists, and what it cost to make.
 *
 * The cost is carried here rather than looked up later because image models are
 * priced per picture, not per token — the run's metering has nowhere to put a
 * token count for this, so the provider reports money directly.
 */
final readonly class GeneratedImage
{
    public function __construct(
        public string $disk,
        public string $path,
        public int $width,
        public int $height,
        public string $provider,
        public string $model,
        public int $costMicros,
    ) {}
}
