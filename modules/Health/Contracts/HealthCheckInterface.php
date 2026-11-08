<?php

declare(strict_types = 1);

namespace Modules\Health\Contracts;

use Modules\Health\Value\HealthResult;

/**
 * HealthCheckInterface — one probe the /health endpoint can run.
 *
 * Ported from marko's `health` package without `marko/core`: the shape is two
 * methods over a value object, which is what makes it the cheapest Phase C
 * port in `openspec/changes/marko-adoption`. A check answers two questions and
 * nothing else — what it is called, and what it found. It never formats a
 * response, so a new check cannot change the endpoint's payload by accident.
 *
 * `check()` must not throw. A broken subsystem is a RESULT (unhealthy, with the
 * exception attached for the log), never an exception that takes the endpoint
 * with it: a /health that answers 500 when the database is down reports nothing
 * exactly when it is needed. Each implemented check catches `\Throwable` itself
 * and returns.
 */
interface HealthCheckInterface
{
    /**
     * Stable, lowercase name. It is the key an operator greps for in the
     * payload, so it is not translated and does not change with config.
     */
    public function getName(): string;

    /**
     * Run the probe. Never throws — see the interface docblock.
     */
    public function check(): HealthResult;
}
