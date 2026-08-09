<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Reads config/models.php: which model plays a role, and what its tokens cost.
 *
 * Everything about model choice and pricing that is not a step's business lives
 * behind this, so a step never names a model and a price change is one config
 * edit rather than a search through the pipeline code.
 */
class ModelCatalog
{
    /** Prices are quoted per million tokens. */
    private const TOKENS_PER_PRICE_UNIT = 1_000_000;

    public function resolve(string $role): ModelChoice
    {
        /** @var array<string, array{provider: string, model: string}> $roles */
        $roles = config('models.roles', []);

        if (! array_key_exists($role, $roles)) {
            // A typo in a role name would otherwise silently fall back to the
            // default model, and the first sign of it would be a factcheck step
            // quietly running on the cheap model.
            throw new InvalidArgumentException(
                "No model role `{$role}` in config/models.php. Known roles: ".implode(', ', array_keys($roles)).'.'
            );
        }

        return new ModelChoice($role, $roles[$role]['provider'], $roles[$role]['model']);
    }

    public function priceListVersion(): int
    {
        return (int) config('models.prices.version', 1);
    }

    /**
     * Cost in micro-USD.
     *
     * `$version` is passed by callers re-pricing a historical run, so it is
     * priced under the list it actually ran on rather than under today's.
     *
     * A model with no price returns zero and says so in the log rather than
     * throwing: an incomplete price list is a configuration mistake, and
     * failing a run that has already spent the money is the one response that
     * makes it worse. It shows up as a zero-cost row next to a token count,
     * which is a shape that reads as wrong.
     */
    public function cost(string $model, int $inputTokens, int $outputTokens, ?int $version = null): int
    {
        $version ??= $this->priceListVersion();

        /** @var array<int, array<string, array{input: int, output: int}>> $lists */
        $lists = config('models.prices.versions', []);
        $price = $lists[$version][$model] ?? null;

        if ($price === null) {
            Log::warning('No price for a model; its cost is recorded as zero', [
                'model' => $model,
                'price_list_version' => $version,
            ]);

            return 0;
        }

        return intdiv(
            ($inputTokens * $price['input']) + ($outputTokens * $price['output']),
            self::TOKENS_PER_PRICE_UNIT,
        );
    }
}
