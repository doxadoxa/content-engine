<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Models\SocialPlan;
use App\Pipelines\Contracts\StepPayload;

/**
 * What the run left behind: a draft, or an empty slot and the reason for it
 * (§4.3, §7).
 *
 * Both are successes and the shape says so — `saved` is false with `reason`
 * filled, rather than the run being red. §4.3: "Пустой слот — валидный результат
 * пайплайна, и в этом всё отличие от генератора." A failed run would put the
 * slot in the same bucket as a broken worker, which is precisely the confusion
 * §7's mandatory last line exists to prevent.
 *
 * `reason` is duplicated here and on the week's {@see SocialPlan}
 * row on purpose. The row is what §7 reads — one query for the whole week — and
 * this is what the run itself carries, so "why did this run produce nothing" is
 * answerable from the run without joining anything.
 */
final readonly class SavedDraftPayload implements StepPayload
{
    public function __construct(
        public string $unitId,
        public bool $saved,
        public int $segments = 0,
        public ?string $assetId = null,
        public ?string $reason = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'unit_id' => $this->unitId,
            'saved' => $this->saved,
            'segments' => $this->segments,
            'asset_id' => $this->assetId,
            'reason' => $this->reason,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static
    {
        return new self(
            unitId: (string) ($data['unit_id'] ?? ''),
            saved: (bool) ($data['saved'] ?? false),
            segments: (int) ($data['segments'] ?? 0),
            assetId: is_string($data['asset_id'] ?? null) && $data['asset_id'] !== ''
                ? $data['asset_id']
                : null,
            reason: is_string($data['reason'] ?? null) && $data['reason'] !== ''
                ? $data['reason']
                : null,
        );
    }
}
