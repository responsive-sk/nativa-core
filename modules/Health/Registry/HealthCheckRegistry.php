<?php

declare(strict_types = 1);

namespace Modules\Health\Registry;

use Modules\Health\Contracts\HealthCheckInterface;
use Modules\Health\Value\HealthResult;

/**
 * HealthCheckRegistry — the ordered set of probes /health runs.
 *
 * Registration order is payload order, and it is the only ordering rule: a
 * monitor diffing two responses over time compares a list, and a list that
 * reshuffles because a check was added in the middle is a diff nobody can read.
 *
 * The registry is a plain mutable object rather than a static singleton (its
 * owner, `HealthServices`, is the singleton). That is what makes the endpoint
 * testable with synthetic checks and no filesystem, database or dist tree
 * involved: the registry is the seam.
 *
 * An empty registry is not an error. `run()` returns an empty list, the action
 * reports `healthy`, and that is the correct answer to "is anything wrong?" on
 * a tree with no probes registered — the alternative (503 for "unconfigured")
 * would make every misconfiguration look like an outage.
 */
final class HealthCheckRegistry
{
    /** @var list<HealthCheckInterface> */
    private array $checks = [];

    public function register(HealthCheckInterface $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * @return list<HealthCheckInterface>
     */
    public function all(): array
    {
        return $this->checks;
    }

    /**
     * Run every registered check, in registration order.
     *
     * No try/catch here: the contract on `HealthCheckInterface::check()` is
     * that it does not throw, and swallowing a broken CHECK (as opposed to a
     * broken subsystem) would hide a defect behind a green endpoint. A check
     * that throws is a bug in the module, and it reaches the error log as one.
     *
     * @return list<HealthResult>
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            $results[] = $check->check();
        }

        return $results;
    }
}
