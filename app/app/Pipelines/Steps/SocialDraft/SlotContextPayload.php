<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Enums\SocialBand;
use App\Models\BrandBrief;
use App\Pipelines\Contracts\StepPayload;
use App\Social\GovernorVerdict;

/**
 * Everything the rest of the run needs about one slot, read once (§4.3).
 *
 * Assembled by {@see LoadContext} on the cheap queue so that
 * {@see DraftCandidates} is eight requests to one provider with no database
 * between them — the expensive queue has the smaller worker pool, and a step
 * that holds one of its workers open while it runs queries is holding it open
 * against every other project's generation.
 *
 * The governor's verdict travels with it because the bar is a fact about the
 * week rather than about the candidate: {@see RankCandidates} compares against
 * `verdict->selectionFloor`, and re-asking the governor a step later would let
 * the bar move between the pool being written and the pool being judged.
 */
final readonly class SlotContextPayload implements StepPayload
{
    /**
     * @param  string  $slotId  the `content_items` row this run is filling
     * @param  string  $brief  {@see BrandBrief::compileToPrompt()}, or ''
     * @param  list<string>  $forbiddenTopics  from the same brief, for the guard
     * @param  list<string>  $vocabulary  the project's entities, longest first
     * @param  array<string, mixed>  $originalData  business facts, for §10
     * @param  list<string>  $parentEntities  empty unless this is a derivative
     */
    public function __construct(
        public string $slotId,
        public SocialBand $band,
        public string $title,
        public string $brief,
        public array $forbiddenTopics,
        public array $vocabulary,
        public array $originalData,
        public ?string $signalTitle,
        public ?string $signalUrl,
        public ?string $parentId,
        public ?string $parentTitle,
        public array $parentEntities,
        public ?string $parentBody,
        public GovernorVerdict $verdict,
    ) {}

    /** Whether §4.3's ≥34% overlap rule applies to this slot. */
    public function isDerivative(): bool
    {
        return $this->parentId !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slot_id' => $this->slotId,
            'band' => $this->band->value,
            'title' => $this->title,
            'brief' => $this->brief,
            'forbidden_topics' => $this->forbiddenTopics,
            'vocabulary' => $this->vocabulary,
            'original_data' => $this->originalData,
            'signal_title' => $this->signalTitle,
            'signal_url' => $this->signalUrl,
            'parent_id' => $this->parentId,
            'parent_title' => $this->parentTitle,
            'parent_entities' => $this->parentEntities,
            'parent_body' => $this->parentBody,
            'verdict' => $this->verdict->toArray(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static
    {
        /** @var array<string, mixed> $verdict */
        $verdict = is_array($data['verdict'] ?? null) ? $data['verdict'] : [];
        /** @var array<string, mixed> $originalData */
        $originalData = is_array($data['original_data'] ?? null) ? $data['original_data'] : [];

        return new self(
            slotId: (string) ($data['slot_id'] ?? ''),
            band: SocialBand::from((string) ($data['band'] ?? SocialBand::Question->value)),
            title: (string) ($data['title'] ?? ''),
            brief: (string) ($data['brief'] ?? ''),
            forbiddenTopics: self::strings($data, 'forbidden_topics'),
            vocabulary: self::strings($data, 'vocabulary'),
            originalData: $originalData,
            signalTitle: self::nullableString($data, 'signal_title'),
            signalUrl: self::nullableString($data, 'signal_url'),
            parentId: self::nullableString($data, 'parent_id'),
            parentTitle: self::nullableString($data, 'parent_title'),
            parentEntities: self::strings($data, 'parent_entities'),
            parentBody: self::nullableString($data, 'parent_body'),
            verdict: GovernorVerdict::fromArray($verdict),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function strings(array $data, string $key): array
    {
        return array_values(array_map(
            strval(...),
            is_array($data[$key] ?? null) ? $data[$key] : [],
        ));
    }

    /** @param  array<string, mixed>  $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
