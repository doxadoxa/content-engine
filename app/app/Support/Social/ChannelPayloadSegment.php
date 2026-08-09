<?php

declare(strict_types=1);

namespace App\Support\Social;

use InvalidArgumentException;

/**
 * One post in a chain, and its own line in the delivery journal (§9).
 *
 * Threads publishes in two phases — create a container, then publish it — and
 * offers no client-supplied idempotency key, so the only record that a segment
 * already went out is the one we keep ourselves. `container_id` is written
 * after the first call and `published_id` after the second, both immediately,
 * because a crash in between is the normal case rather than the unlucky one.
 *
 * `position` is the segment's index in the chain and is *not* stored: it is
 * derived on the way in and dropped on the way out. A resume needs to know
 * where to write the journal entry back, and carrying the index in the JSON
 * would be a second copy of a fact the array order already states — the same
 * argument that keeps `signal_id` out of {@see ChannelPayload}.
 */
final readonly class ChannelPayloadSegment
{
    public function __construct(
        public int $position,
        public string $text,
        public ?string $assetId = null,
        public ?string $containerId = null,
        public ?string $publishedId = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $raw
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $raw, int $position): self
    {
        $text = $raw['text'] ?? null;

        if (! is_string($text)) {
            throw new InvalidArgumentException(
                "Segment {$position} of a channel payload has no text."
            );
        }

        return new self(
            position: $position,
            text: $text,
            assetId: self::nullableString($raw, 'asset_id', $position),
            containerId: self::nullableString($raw, 'container_id', $position),
            publishedId: self::nullableString($raw, 'published_id', $position),
        );
    }

    /** True once the platform has this segment. */
    public function isPublished(): bool
    {
        return $this->publishedId !== null;
    }

    /**
     * The stored shape, in the order §3 writes it. `position` is absent.
     *
     * @return array{text: string, asset_id: string|null, container_id: string|null, published_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'asset_id' => $this->assetId,
            'container_id' => $this->containerId,
            'published_id' => $this->publishedId,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $raw
     *
     * @throws InvalidArgumentException
     */
    private static function nullableString(array $raw, string $key, int $position): ?string
    {
        $value = $raw[$key] ?? null;

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException(
            "Segment {$position} of a channel payload has a non-string {$key}."
        );
    }
}
