<?php

declare(strict_types=1);

namespace App\Media;

use App\Media\Contracts\ImageGenerationProvider;
use App\Pipelines\Exceptions\TerminalStepFailure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The image provider the suite runs against.
 *
 * Writes a real file to a fake disk, so the code under test stores a path that
 * genuinely resolves — a provider that returned a plausible path and no bytes
 * would let a broken storage configuration through.
 */
class FakeImageGeneration implements ImageGenerationProvider
{
    public const int COST_MICROS = 40_000;

    /** @var list<string> */
    private array $prompts = [];

    /** After this many images, refuse — the exhausted-balance case. */
    private ?int $failAfter = null;

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @param  list<string>  $references
     * @param  array<string, mixed>  $options
     */
    public function generate(string $prompt, array $references = [], array $options = []): GeneratedImage
    {
        if ($this->failAfter !== null && count($this->prompts) >= $this->failAfter) {
            throw new TerminalStepFailure('AtlasCloud refused the request with 402. {"code":402,"msg":"insufficient balance"}');
        }

        $this->prompts[] = $prompt;

        $path = 'generated/'.Str::random(24).'.webp';

        Storage::disk('public')->put($path, 'not-really-a-webp');

        return new GeneratedImage(
            disk: 'public',
            path: $path,
            width: (int) ($options['width'] ?? 1200),
            height: (int) ($options['height'] ?? 630),
            provider: 'fake',
            model: 'fake-image',
            costMicros: self::COST_MICROS,
        );
    }

    /**
     * Draw this many, then refuse.
     *
     * The shape a spent balance actually has: the hero comes out and the inline
     * pictures do not, so the article ships looking merely plain.
     */
    public function failAfter(int $images): self
    {
        $this->failAfter = $images;

        return $this;
    }

    /** @return list<string> */
    public function prompts(): array
    {
        return $this->prompts;
    }
}
