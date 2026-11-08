<?php

declare(strict_types = 1);

namespace Modules\Health\Interfaces\HTTP\Actions;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Health\Interfaces\HTTP\HealthServices;
use Modules\Health\Registry\HealthCheckRegistry;
use Modules\Health\Value\HealthResult;
use Modules\Health\Value\HealthStatus;

/**
 * HealthAction — GET /health, the one endpoint a monitor calls.
 *
 * Ported from marko's `HealthController`, minus the routing layer it depends on
 * (`#[Get]` + `Response::json` from `marko/routing`) and minus the shared-secret
 * gate: its `HEALTH_SECRET` was an operator asking "who may see this", and the
 * payload here is four generic fields per check with no paths, no versions and
 * no exception text, so there is nothing behind the gate worth charging an
 * operator for. If a deployment needs one, it is an `Env`-backed check in this
 * action, not a reason to keep the module from shipping.
 *
 * ## 200 vs 503
 *
 * Only `Unhealthy` becomes 503. `Degraded` serves 200 with the status in the
 * body, because a load balancer that pulls a node out for "no bundle built yet"
 * turns a cold start into an outage. Aggregate is worst-wins: one unhealthy
 * check makes the endpoint unhealthy no matter how many passed, and the checks
 * array still lists every result — a monitor reading only the top-level status
 * and a human reading the list are both served by the same response.
 *
 * ## Why the payload cannot drift
 *
 * The mapping lives in `HealthResult::toArray()`, not here, and the exception is
 * not in it. A check added later therefore cannot leak a path or a driver
 * message into a public response by deciding what to print, which is the
 * property that made the value object worth porting separately.
 *
 * `PageCacheMiddleware` never stores this response: it caches only
 * `text/html` 200s, and `/health` is JSON. That is not luck — a cached health
 * answer would report the state of the past for its whole TTL — so it is worth
 * knowing which half of the pipeline is responsible. The other half is this
 * action: `Cache-Control: no-store` travels with the response, because the
 * local page cache is not the only cache a deployment can sit behind. A proxy
 * or CDN with heuristic freshness would otherwise be free to keep serving
 * "healthy" for minutes after the database died, which is the one failure a
 * health endpoint must not have.
 *
 * `HEAD` is routed as well as `GET` for the probes that default to it
 * (`curl -I`, several load-balancer checks). `RateLimitMiddleware` counts both
 * (60/minute per IP), which leaves room for a probe every few seconds.
 */
#[Route(path: '/health', method: 'GET|HEAD', name: 'health')]
final readonly class HealthAction
{
    /**
     * The registry is a constructor parameter with a default so a test can
     * inject synthetic checks; the kernel's `instantiateAction()` passes the
     * default when the route is dispatched, so production wires nothing.
     */
    public function __construct(
        private ?HealthCheckRegistry $registry = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $results = ($this->registry ?? HealthServices::registry())->run();
        $status = self::aggregate($results);

        $response = Response::json(
            data: [
                'status' => $status->value,
                'checks' => array_map(
                    static fn (HealthResult $result): array => $result->toArray(),
                    $results,
                ),
            ],
            statusCode: HealthStatus::Unhealthy === $status ? 503 : 200,
        );

        // See the class docblock: the local page cache already refuses JSON, and
        // this is the half that stops the cache nobody in this repo owns.
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Worst-wins, with an early return on the only status that changes the HTTP
     * code — a degraded result cannot upgrade a later unhealthy one.
     *
     * @param list<HealthResult> $results
     */
    private static function aggregate(array $results): HealthStatus
    {
        $worst = HealthStatus::Healthy;

        foreach ($results as $result) {
            if ($result->isUnhealthy()) {
                return HealthStatus::Unhealthy;
            }

            if ($result->isDegraded()) {
                $worst = HealthStatus::Degraded;
            }
        }

        return $worst;
    }
}
