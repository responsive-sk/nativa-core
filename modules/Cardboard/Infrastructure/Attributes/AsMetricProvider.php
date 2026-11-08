<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Infrastructure\Attributes;

use Modules\Cardboard\Domain\Contracts\MetricContract;

/**
 * Marks a class as a dashboard metric provider for auto-discovery.
 *
 * Metric classes annotated with #[AsMetricProvider] are discovered by
 * MetricRegistry and registered into the dashboard automatically.
 * The priority parameter controls display order (higher = rendered first).
 *
 * The class MUST implement MetricContract and have no required
 * constructor parameters (same constraint as #[AsMiddleware]).
 *
 * @see MetricContract
 * @see MetricRegistry
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsMetricProvider
{
    public function __construct(
        public int $priority = 100,
    ) {
    }
}
