<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Support\Social\ChannelPayload;
use App\Support\Social\PostFormat;

/**
 * One of the five to ten posts the expensive model wrote, before anybody
 * decided which one exists (§4.3).
 *
 * A candidate is not a draft. Seven of eight are thrown away, and the thing
 * that makes throwing them away affordable is that none of them has touched a
 * `ContentItem` — nothing here is written anywhere until {@see SaveDraft}, and
 * even then only for the one that survived both the bar and the guard.
 *
 * The fields are the ones §2 and §3 need and no others. `segments` is a list
 * because a chain is possible and not because it is expected — §2's default is
 * one post, one thought — and `chainReason` is the price of a second entry:
 * "цепочка — исключение, которое шаг обязан обосновать".
 */
final readonly class PostCandidate
{
    /**
     * @param  string  $angle  §2's shape, carried through to {@see ChannelPayload::$angle}
     * @param  list<string>  $segments  one, unless something justified more
     * @param  string|null  $link  the URL the post points at, if any
     * @param  string|null  $chainReason  why this is a chain, in the model's own words
     */
    public function __construct(
        public string $angle,
        public array $segments,
        public ?string $link = null,
        public ?string $chainReason = null,
    ) {}

    /** The whole post as one string, for the checks that read it as prose. */
    public function text(): string
    {
        return trim(implode("\n\n", $this->segments));
    }

    /**
     * What §2 says this one is worth.
     *
     * Computed rather than stored, so a candidate cannot be constructed
     * carrying a score that disagrees with its own text — which is exactly what
     * a model asked to rate its own output would hand back.
     */
    public function score(): int
    {
        return PostFormat::score($this->segments, $this->link, $this->chainReason !== null);
    }

    /** Whether a second segment was asked for and explained. */
    public function isChain(): bool
    {
        return count($this->segments) > 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'angle' => $this->angle,
            'segments' => $this->segments,
            'link' => $this->link,
            'chain_reason' => $this->chainReason,
            // Denormalised into the stored payload on purpose: this is what §7
            // shows an operator asking why the engine kept the one it kept, and
            // recomputing it from a step output months later would answer with
            // today's rules rather than with the ones that decided.
            'score' => $this->score(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $segments */
        $segments = array_values(array_map(
            strval(...),
            is_array($data['segments'] ?? null) ? $data['segments'] : [],
        ));

        return new self(
            angle: (string) ($data['angle'] ?? 'observation'),
            segments: $segments,
            link: self::nullableString($data, 'link'),
            chainReason: self::nullableString($data, 'chain_reason'),
        );
    }

    /** @param  array<string, mixed>  $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
