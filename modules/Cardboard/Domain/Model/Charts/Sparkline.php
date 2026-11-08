<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Domain\Model\Charts;

use Modules\Cardboard\Domain\Contracts\ChartContract;

/**
 * Sparkline — inline SVG line trend chart.
 *
 * Renders a polyline from a list of Y-values (X is evenly spaced).
 * No JS, no external resources, CSP-safe.
 */
final readonly class Sparkline implements ChartContract
{
    /**
     * @param list<int> $points Y-values (x is evenly spaced).
     * @param string    $color  Stroke color (CSS variable or hex).
     * @param int       $width  SVG viewport width.
     * @param int       $height SVG viewport height.
     */
    public function __construct(
        public array $points,
        public string $color = 'var(--m-primary)',
        public int $width = 80,
        public int $height = 32,
    ) {
    }

    public function render(): string
    {
        if ([] === $this->points) {
            return '';
        }

        $count = count($this->points);
        $min = min($this->points);
        $max = max($this->points);
        $range = $max - $min;
        $h = $this->height;

        // Avoid division by zero when all values are equal.
        $norm = 0 === $range
            ? static fn (int $v): float => $h / 2
            : static fn (int $v): float => $h - (($v - $min) / $range) * $h;

        $coords = [];
        for ($i = 0; $i < $count; $i++) {
            $x = $count > 1
                ? round(($i / ($count - 1)) * $this->width, 1)
                : $this->width / 2;
            $y = round($norm($this->points[$i]), 1);
            $coords[] = "{$x},{$y}";
        }

        $pointsStr = implode(' ', $coords);

        return '<svg class="m-chart m-chart--sparkline" width="' . $this->width . '" height="' . $this->height
            . '" viewBox="0 0 ' . $this->width . ' ' . $this->height
            . '" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">'
            . '<polyline points="' . $pointsStr . '"'
            . ' stroke="' . $this->color . '"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>';
    }
}
