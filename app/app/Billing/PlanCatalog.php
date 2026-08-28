<?php

declare(strict_types=1);

namespace App\Billing;

use App\Ai\ModelCatalog;
use InvalidArgumentException;

/**
 * Reads config/billing.php: which plans exist, and what each one permits.
 *
 * Shaped after {@see ModelCatalog} on purpose. Both answer "what does
 * the configuration say", both are the only readers of their config file, and
 * both have a versioned price list underneath them for the same reason: a
 * figure somebody was charged under must keep meaning what it meant.
 *
 * The difference is which way an unknown key fails. An unpriced *model* records
 * a zero and logs, because failing a run that already spent the money makes it
 * worse. An unknown *plan* throws, because there is no safe guess: defaulting
 * up gives away the product and defaulting down locks out a paying customer,
 * and both are silent.
 */
class PlanCatalog
{
    public function currentVersion(): int
    {
        return (int) config('billing.version', 1);
    }

    public function get(string $key, ?int $version = null): Plan
    {
        $version ??= $this->currentVersion();

        /** @var array<int, array<string, array<string, mixed>>> $lists */
        $lists = config('billing.plans', []);
        $row = $lists[$version][$key] ?? null;

        if (! is_array($row)) {
            $known = implode(', ', array_keys($lists[$version] ?? []));

            throw new InvalidArgumentException(
                "No plan `{$key}` in version {$version} of config/billing.php. Known plans: {$known}."
            );
        }

        return Plan::fromConfig($key, $version, $row);
    }

    public function has(string $key, ?int $version = null): bool
    {
        $version ??= $this->currentVersion();

        /** @var array<int, array<string, mixed>> $lists */
        $lists = config('billing.plans', []);

        return is_array($lists[$version][$key] ?? null);
    }

    /**
     * Every plan of a version, in the order the config names them — which is
     * the order they are priced in and therefore the order to show them.
     *
     * @return list<Plan>
     */
    public function all(?int $version = null): array
    {
        $version ??= $this->currentVersion();

        /** @var array<int, array<string, array<string, mixed>>> $lists */
        $lists = config('billing.plans', []);

        $plans = [];

        foreach ($lists[$version] ?? [] as $key => $row) {
            $plans[] = Plan::fromConfig((string) $key, $version, $row);
        }

        return $plans;
    }

    /**
     * The plans somebody can buy without talking to us.
     *
     * Excludes the trial and Enterprise by the same flag, which is right: one
     * cannot be bought and the other is a conversation, and a "Choose" button
     * under either would promise a checkout that does not exist.
     *
     * @return list<Plan>
     */
    public function selfServe(?int $version = null): array
    {
        return array_values(array_filter(
            $this->all($version),
            static fn (Plan $plan): bool => $plan->selfServe,
        ));
    }

    /**
     * The free window, which is a plan like any other.
     *
     * Read out of the versioned list under the version it is asked for, not out
     * of a corner of the config that never moves. Held outside the list it was
     * the one entitlement a re-pricing could still change under somebody who
     * was mid-trial — the precise thing versioning exists to prevent — and it
     * was the easiest to miss, because a trial lasts three days and the drift
     * would only ever show up in one.
     */
    public function trial(?int $version = null): Plan
    {
        return $this->get('trial', $version);
    }

    public function trialDays(): int
    {
        return max(1, (int) config('billing.trial.days', 3));
    }

    public function graceDays(): int
    {
        return max(0, (int) config('billing.grace_days', 7));
    }
}
