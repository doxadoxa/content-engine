<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialPlan;

use App\Enums\SocialBand;
use Carbon\CarbonImmutable;

/**
 * Something the week could say, before anyone has decided whether it will.
 *
 * A candidate is not a slot. §4.3 makes the empty slot a valid result, so the
 * two have to be separate things: gathering is allowed to be generous, and
 * every refusal between here and a `ContentItem` is a sentence §7 shows the
 * operator.
 *
 * `unitId` is the one field that changes what placing it means. Most bands have
 * nothing written yet and placing them creates a unit in `idea` for
 * `social_draft` to fill. The Derivative band is different: the repurpose
 * pipeline already cut the text and saved a `SocialPost` row, so placing it is
 * giving an existing row a time. Creating a second one would publish the same
 * article twice and pay for it twice against §5's budget.
 */
final readonly class SlotCandidate
{
    /**
     * @param  list<string>  $entities  resolved into the project's vocabulary already
     * @param  int  $weight  0–100, on the scale of `SignalWeight`, and what the
     *                       governor's selection bar is compared against
     * @param  string  $why  in the operator's words, for §7
     */
    public function __construct(
        public SocialBand $band,
        public string $title,
        public array $entities,
        public int $weight,
        public string $why,
        public ?string $signalId = null,
        public ?string $parentId = null,
        public ?string $unitId = null,
        public ?string $targetQuery = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'band' => $this->band->value,
            'title' => $this->title,
            'entities' => $this->entities,
            'weight' => $this->weight,
            'why' => $this->why,
            'signal_id' => $this->signalId,
            'parent_id' => $this->parentId,
            'unit_id' => $this->unitId,
            'target_query' => $this->targetQuery,
            'expires_at' => $this->expiresAt?->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $entities */
        $entities = array_values(array_map(
            strval(...),
            is_array($data['entities'] ?? null) ? $data['entities'] : [],
        ));

        return new self(
            band: SocialBand::from((string) ($data['band'] ?? SocialBand::Question->value)),
            title: (string) ($data['title'] ?? ''),
            entities: $entities,
            weight: (int) ($data['weight'] ?? 0),
            why: (string) ($data['why'] ?? ''),
            signalId: self::nullableString($data, 'signal_id'),
            parentId: self::nullableString($data, 'parent_id'),
            unitId: self::nullableString($data, 'unit_id'),
            targetQuery: self::nullableString($data, 'target_query'),
            expiresAt: isset($data['expires_at']) && is_string($data['expires_at'])
                ? CarbonImmutable::parse($data['expires_at'])
                : null,
        );
    }

    /** @param  array<string, mixed>  $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
