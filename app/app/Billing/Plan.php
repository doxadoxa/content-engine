<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * One row of the price list, read out of config/billing.php.
 *
 * A value object rather than an array because every limit on it is consulted
 * from a different place — the tick, six routes, the paywall, the panel — and
 * `$plan['limits']['articles'] ?? null` repeated in all of them is how a typo
 * comes to mean "unlimited" in one of them.
 *
 * `null` is unlimited everywhere and never zero. A plan that fails to name a
 * limit must not silently forbid the thing it forgot.
 */
final readonly class Plan
{
    /**
     * @param  array<string, int|null>  $limits
     */
    private function __construct(
        public string $key,
        public int $version,
        public string $name,
        public int $priceCents,
        public bool $selfServe,
        public ?string $stripePrice,
        private array $limits,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromConfig(string $key, int $version, array $row): self
    {
        /** @var array<string, int|null> $limits */
        $limits = is_array($row['limits'] ?? null) ? $row['limits'] : [];
        $stripePrice = $row['stripe_price'] ?? null;

        return new self(
            key: $key,
            version: $version,
            name: is_string($row['name'] ?? null) ? $row['name'] : ucfirst($key),
            priceCents: (int) ($row['price_cents'] ?? 0),
            selfServe: (bool) ($row['self_serve'] ?? false),
            stripePrice: is_string($stripePrice) && $stripePrice !== '' ? $stripePrice : null,
            limits: $limits,
        );
    }

    /**
     * A plan whose limits have been widened or narrowed for one customer.
     *
     * This is what Enterprise is: the config row names the shape and the
     * subscription row names the numbers. Overrides are merged rather than
     * replacing the set, so a bespoke article count does not accidentally make
     * every unnamed limit unlimited.
     *
     * @param  array<string, int|null>  $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            key: $this->key,
            version: $this->version,
            name: $this->name,
            priceCents: $this->priceCents,
            selfServe: $this->selfServe,
            stripePrice: $this->stripePrice,
            limits: [...$this->limits, ...$overrides],
        );
    }

    /** Null means unlimited. */
    public function limit(string $metric): ?int
    {
        $value = $this->limits[$metric] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * The ceiling on the project's own cadence dial.
     *
     * Falls back to the article allowance divided across the weeks of a month
     * when a plan does not name one, so a new plan cannot accidentally ship
     * with an unlimited engine.
     */
    public function weeklyTarget(): ?int
    {
        $named = $this->limit('weekly_target');

        if ($named !== null) {
            return max(1, $named);
        }

        $articles = $this->limit('articles');

        return $articles === null ? null : max(1, (int) round($articles / 4.34));
    }

    /** @return array<string, int|null> */
    public function limits(): array
    {
        return $this->limits;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'name' => $this->name,
            'price_cents' => $this->priceCents,
            'self_serve' => $this->selfServe,
            'limits' => $this->limits,
        ];
    }
}
