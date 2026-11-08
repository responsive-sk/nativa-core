<?php

declare(strict_types = 1);

namespace Modules\Health\Value;

/**
 * HealthStatus — the three answers a check may give.
 *
 * `Degraded` exists so an installed-but-incomplete surface (no theme bundle
 * built, no database file to probe yet) does not have to pick between a lie and
 * an outage. Only `Unhealthy` becomes a 503; `Degraded` still serves 200 and
 * says what is missing in the body, because a monitor that treats "not finished
 * bootstrapping" as "down" pages someone for a fresh clone.
 */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
}
