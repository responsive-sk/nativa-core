<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Domain\Model;

/**
 * Trend — immutable value object for period-over-period metric comparison.
 *
 * Computes direction (up/down/flat), percentage change, and renders
 * a BEM-styled HTML badge for dashboard stat cards.
 *
 * Usage:
 *   $trend = Trend::make(42, 35);
 *   $trend->render();  // '<span class="m-trend m-trend--up">↑ +20%</span>'
 */
final readonly class Trend
{
    public function __construct(
        public int $current,
        public int $previous,
    ) {
    }

    public static function make(int $current, int $previous): self
    {
        return new self(current: $current, previous: $previous);
    }

    /**
     * Absolute change: current - previous.
     */
    public function change(): int
    {
        return $this->current - $this->previous;
    }

    /**
     * Percentage change relative to previous value.
     *
     * When there is no baseline (previous = 0): 0% stays flat, while any
     * positive current value is reported as 100% growth from zero (the
     * division-by-zero guard can never produce a misleading "0%" badge).
     */
    public function percentage(): float
    {
        if (0 === $this->previous) {
            return $this->current > 0 ? 100.0 : 0.0;
        }

        return round((($this->current - $this->previous) / $this->previous) * 100, 1);
    }

    /**
     * Direction of change: 'up', 'down', or 'flat'.
     */
    public function direction(): string
    {
        $change = $this->change();

        if ($change > 0) {
            return 'up';
        }

        if ($change < 0) {
            return 'down';
        }

        return 'flat';
    }

    /**
     * Arrow character: ↑, ↓, or —.
     */
    public function arrow(): string
    {
        return match ($this->direction()) {
            'up' => '↑',
            'down' => '↓',
            default => '—',
        };
    }

    /**
     * Whether the trend is positive (current > previous).
     */
    public function isPositive(): bool
    {
        return $this->current > $this->previous;
    }

    /**
     * Render as a BEM-styled HTML badge.
     *
     * Examples:
     *   <span class="m-trend m-trend--up">↑ +12.5%</span>
     *   <span class="m-trend m-trend--down">↓ −3.2%</span>
     *   <span class="m-trend m-trend--flat">— 0%</span>
     */
    public function render(): string
    {
        $dir = $this->direction();
        $pct = $this->percentage();
        $sign = $pct > 0 ? '+' : '';
        $class = 'm-trend m-trend--' . $dir;

        return '<span class="' . $class . '">'
            . $this->arrow() . ' ' . $sign . $pct . '%'
            . '</span>';
    }
}
