<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Domain\Contracts;

use Modules\Cardboard\Domain\Model\Period;

/**
 * MetricContract — interface for module metric providers.
 *
 * Each module implements this interface to register its own aggregated
 * stat cards into CardboardRegistry. The MetricRegistry auto-discovers
 * classes annotated with #[AsMetricProvider] and calls registerCards().
 *
 * Metric classes MUST have no required constructor parameters
 * (same constraint as middleware). Dependencies are resolved internally
 * via lazy factories (e.g., DatabaseConnectionManager::instance()).
 *
 * @see AsMetricProvider
 * @see MetricRegistry
 */
interface MetricContract
{
    /**
     * Register this module's metric cards into CardboardRegistry.
     *
     * @param Period $period The selected time period for trend comparison.
     */
    public function registerCards(Period $period): void;
}
