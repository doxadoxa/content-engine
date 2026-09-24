<?php

declare(strict_types=1);

namespace App\Billing;

use App\Ai\ModelCatalog;
use InvalidArgumentException;

/**
 * Reads config/billing.php: which plans exist, and what each one permits.
 *
 * Shaped after {@see ModelCatalog} on purpose. Both answer "what does
 * the configuration say", and both are the only readers of their config file.
 *
 * The difference is which way an unknown key fails. An unpriced *model* records
 * a zero and logs, because failing a run that already spent the money makes it
 * worse. An unknown *plan* throws, because there is no safe guess: defaulting
 * up gives away the product and defaulting down locks out a paying customer,
 * and both are silent.
 */
class PlanCatalog
{
    public function defaultPlan(): Plan
    {
        $key = (string) config('billing.default_plan', 'starter');

        // A deployment default naming a plan that is gone must not break signup.
        return $this->has($key) && $this->get($key)->selfServe
            ? $this->get($key)
            : $this->selfServe()[0];
    }

    public function get(string $key): Plan
    {
        $row = $this->rows()[$key] ?? null;

        if (! is_array($row)) {
            $known = implode(', ', array_keys($this->rows()));

            throw new InvalidArgumentException(
                "No plan `{$key}` in config/billing.php. Known plans: {$known}."
            );
        }

        return Plan::fromConfig($key, $row);
    }

    public function has(string $key): bool
    {
        return is_array($this->rows()[$key] ?? null);
    }

    /**
     * Every plan, in the order the config names them — which is the order they
     * are priced in and therefore the order to show them.
     *
     * @return list<Plan>
     */
    public function all(): array
    {
        $plans = [];

        foreach ($this->rows() as $key => $row) {
            $plans[] = Plan::fromConfig((string) $key, $row);
        }

        return $plans;
    }

    public function forStripePrice(string $price): ?Plan
    {
        $matches = array_values(array_filter($this->all(), static fn (Plan $plan): bool => $plan->stripePrice === $price));

        // One price configured for two plans is ambiguous, not a match.
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * The plans somebody can buy without talking to us.
     *
     * Excludes the preview and the trial by the same flag, which is right:
     * neither can be bought, and a "Choose" button under either would promise
     * a checkout that does not exist.
     *
     * @return list<Plan>
     */
    public function selfServe(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (Plan $plan): bool => $plan->selfServe,
        ));
    }

    /** The free window, which is a plan like any other. */
    public function trial(): Plan
    {
        return $this->get('trial');
    }

    public function trialDays(): int
    {
        return max(1, (int) config('billing.trial.days', 3));
    }

    public function graceDays(): int
    {
        return max(0, (int) config('billing.grace_days', 7));
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        /** @var array<string, array<string, mixed>> $rows */
        $rows = config('billing.plans', []);

        return $rows;
    }
}
