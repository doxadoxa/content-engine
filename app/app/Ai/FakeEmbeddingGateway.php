<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\EmbeddingGateway;

/**
 * Deterministic embeddings for the suite.
 *
 * Derived from the text rather than random, and that is what makes exit
 * criterion 3 testable at all: two texts about the same thing have to come out
 * closer than two texts about different things, and a random vector cannot
 * demonstrate that. The trick is a bag of words hashed into buckets — crude,
 * but it has the one property the real thing has and the tests depend on.
 */
class FakeEmbeddingGateway implements EmbeddingGateway
{
    public const int DIMENSIONS = 1536;

    private int $calls = 0;

    /** @var array<string, list<float>> */
    private array $scripted = [];

    /**
     * Pin an exact vector for an exact text.
     *
     * The bag of words below cannot express the one case the duplicate check
     * exists for: "Limpeza de carpetes" and "carpet cleaning" are the same
     * subject and share no word, so hashing them puts them nowhere near each
     * other. A test about meaning has to be able to say what the meaning is.
     *
     * @param  list<float>  $vector  as many dimensions as you care to give; the
     *                               rest are zero
     */
    public function willEmbed(string $text, array $vector): self
    {
        $padded = array_pad($vector, self::DIMENSIONS, 0.0);

        $this->scripted[mb_strtolower(trim($text))] = $this->normalise($padded);

        return $this;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function dimensions(): int
    {
        return self::DIMENSIONS;
    }

    /** @return list<float> */
    public function embed(string $text): array
    {
        $this->calls++;

        $scripted = $this->scripted[mb_strtolower(trim($text))] ?? null;

        if ($scripted !== null) {
            return $scripted;
        }

        $buckets = array_fill(0, self::DIMENSIONS, 0.0);

        foreach (preg_split('/\W+/u', mb_strtolower($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            // Same word, same bucket, every time — which is the whole point.
            $bucket = abs(crc32($word)) % self::DIMENSIONS;
            $buckets[$bucket] += 1.0;
        }

        /** @var list<float> $vector */
        $vector = array_values($buckets);

        return $this->normalise($vector);
    }

    public function callCount(): int
    {
        return $this->calls;
    }

    /**
     * Unit length, so cosine distance behaves and a long document is not
     * automatically far from a short one about the same subject.
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function normalise(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        if ($magnitude === 0.0) {
            // An empty document. A zero vector has no direction, so give it an
            // arbitrary but consistent one rather than a division by zero.
            $vector[0] = 1.0;

            return $vector;
        }

        return array_map(static fn (float $v): float => $v / $magnitude, $vector);
    }
}
