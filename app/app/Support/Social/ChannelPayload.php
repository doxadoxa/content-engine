<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Support\Duty\DutyHours;
use InvalidArgumentException;

/**
 * `content_items.channel_payload`: what the adapter publishes, and the journal
 * of what it already published (§3, §9).
 *
 * §3 gives the shape literally, which is why it is worth a class rather than an
 * array shape in a docblock: the same JSON is read by the Threads adapter, by
 * the resume path, and by whatever renders a preview to the operator, and prose
 * in a migration is not a contract any of the three can be held to.
 *
 * The second job is the one that matters. §9 states plainly that Threads offers
 * no idempotency key and that publishing is two-phase, so a delivery job writes
 * `container_id` and `published_id` back **after every call** and a retry
 * resumes at the first segment without a `published_id`. This per-segment
 * journal is the only thing standing between a worker killed mid-chain and a
 * second root post — exit criterion 4, and the failure that is loudest in
 * public. Hence {@see unpublishedSegments()}, {@see nextUnpublishedSegment()}
 * and {@see rootPostId()}: the questions a resume asks, answered here so that
 * no caller re-derives them from the array and gets the off-by-one wrong.
 * The Threads publisher asks them and derives nothing of its own.
 *
 * **`signal_id` is deliberately not in this payload**, though §3's example
 * carries it. Phase 12.1 made it a real, indexed, foreign-keyed column on
 * `content_items` — the column §3 itself calls the point of signals becoming a
 * table, since the loop learns by source. Keeping it in the JSON as well would
 * be two sources of truth for one fact, and the pair goes wrong in the way that
 * is hardest to notice: `whereNotNull('signal_id')` and the payload would
 * disagree about which posts the news contour produced, and the attribution
 * question §3 exists to answer would get two different numbers. A small,
 * deliberate divergence from the spec's example beats that.
 *
 * Strict where {@see DutyHours} is tolerant, and for the opposite reason. Duty
 * hours are typed by an operator and the safe direction for a half-typed window
 * is to drop it, because dropping one only ever means publishing less. This
 * column is written by machines, and the safe direction for an unreadable
 * journal is to stop: a `published_id` quietly discarded because it arrived as
 * an int is a root post published twice.
 */
final readonly class ChannelPayload
{
    /**
     * @param  list<ChannelPayloadSegment>  $segments
     */
    public function __construct(
        public array $segments = [],
        public ?string $linkAttachment = null,
        public ?string $replyControl = null,
        public ?string $angle = null,
        public ?string $permalink = null,
        public ?string $publishedRootId = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $raw
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $raw): self
    {
        $rawSegments = $raw['segments'] ?? [];

        if (! is_array($rawSegments)) {
            throw new InvalidArgumentException('A channel payload\'s segments must be a list.');
        }

        $segments = [];
        $position = 0;

        foreach ($rawSegments as $segment) {
            if (! is_array($segment)) {
                throw new InvalidArgumentException("Segment {$position} of a channel payload is not an object.");
            }

            $segments[] = ChannelPayloadSegment::fromArray($segment, $position);

            $position++;
        }

        return new self(
            segments: $segments,
            linkAttachment: self::nullableString($raw, 'link_attachment'),
            replyControl: self::nullableString($raw, 'reply_control'),
            angle: self::nullableString($raw, 'angle'),
            permalink: self::nullableString($raw, 'permalink'),
            publishedRootId: self::nullableString($raw, 'published_root_id'),
        );
    }

    /**
     * The root post's id on the platform, or null while nothing is live.
     *
     * Either field answers it, and both are consulted rather than one, because
     * the two are written by the same call and a payload where they disagree is
     * a payload that was interrupted between two lines. The question being
     * asked is "could a retry create a second root post", so the answer has to
     * be yes on any evidence at all that the first one is out.
     *
     * The id rather than a boolean, because every caller that wants the
     * question also wants the answer: a chained segment replies to this id, and
     * a permalink is read from it.
     */
    public function rootPostId(): ?string
    {
        return $this->publishedRootId ?? ($this->segments[0] ?? null)?->publishedId;
    }

    /** Whether the root post exists on the platform. */
    public function hasPublishedRoot(): bool
    {
        return $this->rootPostId() !== null;
    }

    /**
     * Every segment a resume still has to publish, in chain order.
     *
     * Filtered on "has no `published_id`" rather than counted from the front.
     * The journal is written after every call, so a gap in the middle means a
     * segment failed while a later one somehow did not — a chain interrupted
     * and partly resumed, or two attempts that overlapped — and anything that
     * counts published segments instead of naming them publishes the wrong
     * text and leaves the gap unpublished forever.
     *
     * @return list<ChannelPayloadSegment>
     */
    public function unpublishedSegments(): array
    {
        return array_values(array_filter(
            $this->segments,
            static fn (ChannelPayloadSegment $segment): bool => ! $segment->isPublished(),
        ));
    }

    /**
     * Where a retry picks the chain back up, or null when it is complete.
     *
     * Asked once per segment by the publisher, against the journal as it
     * stands after the previous write, so that what goes out next is decided
     * by the committed document rather than by a list captured before the
     * first call.
     */
    public function nextUnpublishedSegment(): ?ChannelPayloadSegment
    {
        return $this->unpublishedSegments()[0] ?? null;
    }

    /**
     * The stored shape, in the order §3 writes it — minus `signal_id`.
     *
     * Every key is always present, including the null ones. A missing
     * `published_id` and a null one mean the same thing to this class and must
     * keep meaning the same thing to anything reading the column with `->>`.
     *
     * @return array{
     *     segments: list<array{text: string, asset_id: string|null, container_id: string|null, published_id: string|null}>,
     *     link_attachment: string|null,
     *     reply_control: string|null,
     *     angle: string|null,
     *     permalink: string|null,
     *     published_root_id: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'segments' => array_map(
                static fn (ChannelPayloadSegment $segment): array => $segment->toArray(),
                $this->segments,
            ),
            'link_attachment' => $this->linkAttachment,
            'reply_control' => $this->replyControl,
            'angle' => $this->angle,
            'permalink' => $this->permalink,
            'published_root_id' => $this->publishedRootId,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $raw
     *
     * @throws InvalidArgumentException
     */
    private static function nullableString(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException("A channel payload's {$key} must be a string or null.");
    }
}
