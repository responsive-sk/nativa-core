<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Domain\Contracts;

/**
 * ChartContract — interface for inline SVG chart visualization.
 *
 * Charts render static SVG markup — no JS, no external resources,
 * CSP-safe. Implementations: Sparkline (trend), Donut (distribution),
 * Bar (categorical comparison).
 */
interface ChartContract
{
    /**
     * Render the chart as an inline SVG string.
     */
    public function render(): string;
}
