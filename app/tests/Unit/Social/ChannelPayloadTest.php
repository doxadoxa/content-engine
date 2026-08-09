<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Support\Social\ChannelPayload;
use App\Support\Social\ChannelPayloadSegment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §3's `channel_payload`, which is also §9's delivery journal.
 *
 * Almost everything here is one requirement wearing different clothes: a
 * segment that was published must never look unpublished. Threads has no
 * idempotency key and publishes in two phases, so the journal is the only
 * record a retry can consult, and the cost of losing one `published_id` is a
 * second root post in public — exit criterion 4. That is why the round trip is
 * tested for nulls rather than for values, and why a malformed payload throws
 * instead of being repaired.
 */
final class ChannelPayloadTest extends TestCase
{
    #[Test]
    public function the_spec_shape_survives_a_round_trip_nulls_and_all(): void
    {
        $stored = [
            'segments' => [
                ['text' => 'A question nobody wrote an article about.', 'asset_id' => null, 'container_id' => null, 'published_id' => null],
                ['text' => 'The second beat.', 'asset_id' => '01JQ', 'container_id' => 'c-2', 'published_id' => null],
            ],
            'link_attachment' => null,
            'reply_control' => 'everyone',
            'angle' => 'question',
            'permalink' => null,
            'published_root_id' => null,
        ];

        $payload = ChannelPayload::fromArray($stored);

        // Key for key, null for null. A `published_id` that came back missing
        // rather than null would read as "not published yet" to a resume, and
        // the resume would publish the root a second time.
        $this->assertSame($stored, $payload->toArray());
    }

    #[Test]
    public function signal_id_is_dropped_rather_than_carried(): void
    {
        $payload = ChannelPayload::fromArray([
            'segments' => [['text' => 'One.', 'asset_id' => null, 'container_id' => null, 'published_id' => null]],
            'signal_id' => '01JQSIGNAL',
            'reply_control' => 'everyone',
        ]);

        // §3's example carries it; 12.1 made it a real indexed column on
        // content_items. Two sources of truth for "which signal produced this
        // post" is worse than a small divergence from the example, because the
        // attribution question §3 exists to answer would get two answers.
        $this->assertArrayNotHasKey('signal_id', $payload->toArray());
    }

    #[Test]
    public function a_resume_restarts_at_the_first_segment_without_a_published_id(): void
    {
        $payload = ChannelPayload::fromArray([
            'segments' => [
                ['text' => 'Root.', 'container_id' => 'c-1', 'published_id' => 'p-1'],
                ['text' => 'Second.', 'container_id' => 'c-2', 'published_id' => null],
                ['text' => 'Third.'],
            ],
            'published_root_id' => 'p-1',
        ]);

        $next = $payload->nextUnpublishedSegment();

        $this->assertInstanceOf(ChannelPayloadSegment::class, $next);
        $this->assertSame(1, $next->position);
        $this->assertSame('Second.', $next->text);

        // The container survived the crash, so the retry publishes it rather
        // than creating a second one.
        $this->assertSame('c-2', $next->containerId);
    }

    #[Test]
    public function a_fully_published_chain_has_nothing_left_to_resume(): void
    {
        $payload = ChannelPayload::fromArray([
            'segments' => [
                ['text' => 'Root.', 'published_id' => 'p-1'],
                ['text' => 'Second.', 'published_id' => 'p-2'],
            ],
            'published_root_id' => 'p-1',
        ]);

        $this->assertNull($payload->nextUnpublishedSegment());
    }

    #[Test]
    public function the_root_counts_as_published_on_either_piece_of_evidence(): void
    {
        $untouched = ChannelPayload::fromArray([
            'segments' => [['text' => 'Root.', 'published_id' => null]],
        ]);

        $journalled = ChannelPayload::fromArray([
            'segments' => [['text' => 'Root.', 'published_id' => 'p-1']],
            'published_root_id' => null,
        ]);

        $promoted = ChannelPayload::fromArray([
            'segments' => [['text' => 'Root.', 'published_id' => null]],
            'published_root_id' => 'p-1',
        ]);

        $this->assertFalse($untouched->hasPublishedRoot());

        // The two fields are written by the same call, so a payload where they
        // disagree was interrupted between two lines. The question being asked
        // is "could a retry post the root again", and any evidence at all that
        // it is already out has to answer it.
        $this->assertTrue($journalled->hasPublishedRoot());
        $this->assertTrue($promoted->hasPublishedRoot());
    }

    #[Test]
    public function an_empty_payload_is_a_chain_that_has_not_started(): void
    {
        $payload = new ChannelPayload;

        $this->assertFalse($payload->hasPublishedRoot());
        $this->assertNull($payload->nextUnpublishedSegment());
        $this->assertSame([], $payload->toArray()['segments']);
    }

    #[Test]
    public function a_journal_entry_of_the_wrong_type_is_refused_rather_than_dropped(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Strict where DutyHours is tolerant, and for the opposite reason:
        // duty hours are typed by an operator and dropping a bad window only
        // publishes less, while a published_id silently discarded because it
        // arrived as an int publishes the root twice.
        ChannelPayload::fromArray([
            'segments' => [['text' => 'Root.', 'published_id' => 17_899_999_999]],
        ]);
    }

    #[Test]
    public function a_segment_without_text_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ChannelPayload::fromArray(['segments' => [['asset_id' => '01JQ']]]);
    }
}
