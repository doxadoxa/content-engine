<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Ai\ModelResponse;
use App\Pipelines\Contracts\StepPayload;

/**
 * The picture, and what it cost (§2, §8).
 *
 * §2 puts an image in the default shape of a post — "Дефолт — один пост, одна
 * мысль, **с картинкой**", and images beat text by around 60% — so the asset id
 * is what {@see SaveDraft} hangs on the first segment of the payload.
 *
 * The cost is carried separately from the model metering the runner does. An
 * image is not a token spend and `pipeline_steps.cost_micros` only knows about
 * {@see ModelResponse}, so §8's "картинки" line reads this.
 */
final readonly class PostImagePayload implements StepPayload
{
    public function __construct(
        public string $assetId,
        public int $costMicros,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['asset_id' => $this->assetId, 'cost_micros' => $this->costMicros];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static
    {
        return new self(
            assetId: (string) ($data['asset_id'] ?? ''),
            costMicros: (int) ($data['cost_micros'] ?? 0),
        );
    }
}
