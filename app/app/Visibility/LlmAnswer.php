<?php

declare(strict_types=1);

namespace App\Visibility;

/**
 * What one assistant said, once.
 *
 * Deliberately not a model. Whether this counts as the brand being visible is a
 * judgement the visibility pipeline makes — it knows the brand's name and
 * domain, and this does not.
 */
final readonly class LlmAnswer
{
    /**
     * @param  list<array{url: string, title: string}>  $citations  sources the assistant said it used
     * @param  float  $moneySpent  what the underlying provider charged, in USD
     */
    public function __construct(
        public string $platform,
        public string $model,
        public string $text,
        public array $citations = [],
        public float $moneySpent = 0.0,
    ) {}

    /**
     * The hosts this answer pointed at, deduplicated, in order of first mention.
     *
     * Hosts rather than urls because the question the panel asks is "who does
     * the assistant trust on this subject", and three pages of one publication
     * is one answer to that, not three.
     *
     * @return list<string>
     */
    public function citedHosts(): array
    {
        $hosts = [];

        foreach ($this->citations as $citation) {
            $host = mb_strtolower((string) parse_url($citation['url'], PHP_URL_HOST));

            if ($host === '') {
                continue;
            }

            // A citation of example.com and one of www.example.com are the same
            // publication disagreeing with itself about a prefix.
            $host = (string) preg_replace('/^www\./', '', $host);

            $hosts[$host] = true;
        }

        return array_keys($hosts);
    }
}
