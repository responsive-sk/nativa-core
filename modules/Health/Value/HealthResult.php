<?php

declare(strict_types = 1);

namespace Modules\Health\Value;

/**
 * HealthResult — what one check found, and the only shape /health serializes.
 *
 * The constructor keeps `$exception`, because a failing check without the
 * failure behind it is a post-mortem with the cause removed; `toArray()`
 * deliberately drops it. That split is the reason this value exists rather than
 * an array: the payload is public, the exception is for `storage/logs`, and no
 * check can leak a path, a driver message or a DSN into a response by
 * formatting its own failure.
 *
 * `duration` is measured by the check around its own work, in seconds — the
 * unit `microtime(true)` already speaks. Nobody sums it; an operator reads it
 * when a probe is slow, and a check that produces the same status in 300 ms as
 * in 3 ms is telling two different stories.
 *
 * `toArray()` publishes MILLISECONDS, not seconds, and that is a measured
 * decision. `json_encode` writes the shortest representation that round-trips,
 * so a fast probe in seconds comes out as `8.20159912109375e-5` — and the first
 * person who read the endpoint read that as "8 seconds". Rounding does not fix
 * it: `round(8.20159912109375e-5, 6)` still encodes as `8.2e-5`. A millisecond
 * value for the same probe is `0.082`, which is both shorter and unreadable as
 * anything but a fraction of a millisecond.
 *
 * marko's `metadata` field is not ported: nothing in that package ever filled
 * it, and an always-empty field in a public payload is a promise the module
 * does not keep.
 */
final readonly class HealthResult
{
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public string $message,
        public float $duration,
        public ?\Throwable $exception = null,
    ) {
    }

    public function isHealthy(): bool
    {
        return HealthStatus::Healthy === $this->status;
    }

    public function isDegraded(): bool
    {
        return HealthStatus::Degraded === $this->status;
    }

    public function isUnhealthy(): bool
    {
        return HealthStatus::Unhealthy === $this->status;
    }

    /**
     * The public projection — name, status, message, duration_ms, nothing else.
     *
     * The key says `_ms` because the unit is not visible in a bare number, and
     * the value is rounded to three decimals: a microsecond of resolution, well
     * under the cost of any probe worth reporting, and far enough from the
     * exponent switch that no realistic duration changes shape.
     *
     * @return array{name: string, status: string, message: string, duration_ms: float}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'message' => $this->message,
            'duration_ms' => round($this->duration * 1000, 3),
        ];
    }
}
