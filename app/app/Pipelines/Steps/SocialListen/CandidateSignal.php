<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Enums\SignalKind;
use App\Enums\SignalSource;
use App\Models\Signal;
use App\Support\Social\SignalWeight;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A `Signal` that has not been written yet (§4.1).
 *
 * Three intakes produce four shapes, and every one of them has to be
 * deduplicated against thirty days of publications and against the queue before
 * any of it reaches the table. So normalisation produces these, dedup filters
 * them, and only then does anything touch the database — which is what keeps
 * the dedup of §4.1 a decision rather than a `catch` around a unique violation.
 *
 * The fingerprint is carried rather than recomputed at each hop. It is the key
 * the whole contour turns on and it is a pure function of the title and the
 * entities, so computing it once at normalisation means the dedup pass and the
 * write cannot disagree about what this candidate is about.
 */
final readonly class CandidateSignal
{
    /**
     * @param  list<string>  $entities  resolved into the project's vocabulary (§4.3)
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public SignalKind $kind,
        public SignalSource $source,
        public ?string $externalId,
        public string $fingerprint,
        public string $title,
        public ?string $url,
        public array $entities,
        public CarbonImmutable $occurredAt,
        public ?CarbonImmutable $expiresAt,
        public int $weight,
        public array $raw,
    ) {}

    /**
     * Build one, computing the fingerprint from the subject.
     *
     * @param  list<string>  $entities
     * @param  array<string, mixed>  $raw
     *
     * @throws \InvalidArgumentException when there is no subject to hash
     */
    public static function make(
        SignalKind $kind,
        SignalSource $source,
        ?string $externalId,
        string $title,
        ?string $url,
        array $entities,
        CarbonImmutable $occurredAt,
        ?CarbonImmutable $expiresAt,
        int $weight,
        array $raw = [],
    ): self {
        return new self(
            kind: $kind,
            source: $source,
            externalId: $externalId,
            fingerprint: Signal::fingerprintFor($title, $entities),
            title: $title,
            url: $url,
            entities: $entities,
            occurredAt: $occurredAt,
            expiresAt: $expiresAt,
            weight: $weight,
            raw: $raw,
        );
    }

    /** The same candidate with a different weight — see {@see SignalWeight::corroborated()}. */
    public function weighing(int $weight): self
    {
        return new self(
            kind: $this->kind,
            source: $this->source,
            externalId: $this->externalId,
            fingerprint: $this->fingerprint,
            title: $this->title,
            url: $this->url,
            entities: $this->entities,
            occurredAt: $this->occurredAt,
            expiresAt: $this->expiresAt,
            weight: $weight,
            raw: $this->raw,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'source' => $this->source->value,
            'external_id' => $this->externalId,
            'fingerprint' => $this->fingerprint,
            'title' => $this->title,
            'url' => $this->url,
            'entities' => $this->entities,
            // ISO 8601 in UTC, because this crosses a json column and comes
            // back on another worker. The model's own docblock spells out what
            // a local time bound into one of these columns costs: a dedup
            // window off by the project's offset.
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'weight' => $this->weight,
            'raw' => $this->raw,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $raw */
        $raw = is_array($data['raw'] ?? null) ? $data['raw'] : [];

        return new self(
            kind: SignalKind::from((string) ($data['kind'] ?? SignalKind::Question->value)),
            source: SignalSource::from((string) ($data['source'] ?? SignalSource::ThreadsKeywordSearch->value)),
            externalId: self::text($data['external_id'] ?? null),
            fingerprint: (string) ($data['fingerprint'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            url: self::text($data['url'] ?? null),
            entities: array_values(array_map(strval(...), is_array($data['entities'] ?? null) ? $data['entities'] : [])),
            occurredAt: self::instant($data['occurred_at'] ?? null) ?? CarbonImmutable::now()->utc(),
            expiresAt: self::instant($data['expires_at'] ?? null),
            weight: (int) ($data['weight'] ?? 0),
            raw: $raw,
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
