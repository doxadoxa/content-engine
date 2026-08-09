<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * One post as `GET /keyword_search` returns it (§2).
 *
 * Typed rather than the raw array, because the whole point of §4.1 is that the
 * listening contour hands the article planner "оригинальную фактуру" — live
 * questions in live words. The words are the payload, and an array whose keys
 * depend on which `fields` the last caller happened to ask for is not a payload
 * anything downstream can rely on.
 *
 * `raw` is kept alongside so nothing is lost on the way in: `signals.raw` is a
 * jsonb column precisely so that a field this class does not model yet is still
 * on disk when somebody wants it.
 *
 * ⚠️ Written from Meta's published documentation and **not verified against a
 * live account**. The field names below are the documented ones and nothing
 * here has seen a real response.
 */
final readonly class ThreadsPost
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $text,
        public ?string $username,
        public ?string $permalink,
        public ?CarbonImmutable $postedAt,
        public ?string $mediaType,
        public array $raw,
    ) {}

    /**
     * Build one from a row of `data`, or nothing at all.
     *
     * Null rather than an exception for a row without an id, and the choice
     * matters: a search answer is a list somebody else's service composed, and
     * one malformed row in a hundred must not cost the other ninety-nine. An id
     * is the one field with no sensible default — it is what the dedup of §4.1
     * keys on and what a reply would be addressed to — so a row without one is
     * dropped rather than invented.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromApi(array $row): ?self
    {
        $id = $row['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        return new self(
            id: $id,
            text: is_string($row['text'] ?? null) ? $row['text'] : '',
            username: self::string($row, 'username'),
            permalink: self::string($row, 'permalink'),
            postedAt: self::timestamp($row['timestamp'] ?? null),
            mediaType: self::string($row, 'media_type'),
            raw: $row,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function string(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The platform's `timestamp`, which is ISO 8601 when it is anything.
     *
     * Unparseable dates become null instead of now(). A post stamped with the
     * moment we read it would look fresh forever, and §5's reactive band kills
     * drafts by age — a wrong date there publishes a comment on the day before
     * yesterday, which §5 says is worse than silence.
     */
    private static function timestamp(mixed $value): ?CarbonImmutable
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
