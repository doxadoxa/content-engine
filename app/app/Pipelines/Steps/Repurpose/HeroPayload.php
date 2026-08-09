<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Repurpose;

use App\Pipelines\Contracts\StepPayload;

/** The hero image, or nothing if the project has no image provider. */
final readonly class HeroPayload implements StepPayload
{
    public function __construct(
        public ?string $assetId = null,
        public int $costMicros = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['asset_id' => $this->assetId, 'cost_micros' => $this->costMicros];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            assetId: isset($data['asset_id']) ? (string) $data['asset_id'] : null,
            costMicros: (int) ($data['cost_micros'] ?? 0),
        );
    }
}
