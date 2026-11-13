<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Domain\Model\Charts;

use Modules\Cardboard\Domain\Contracts\ChartContract;

/**
 * Donut — inline SVG segment distribution chart.
 *
 * Uses the stroke-dasharray / stroke-dashoffset trick on <circle>
 * elements to render concentric arcs. Each segment is a circle with
 * a colored stroke covering its proportion of the circumference.
 */
final readonly class Donut implements ChartContract
{
    /**
     * @param list<array{value: int, color: string}> $segments Distribution data.
     * @param int                                    $size     SVG width/height.
     * @param int                                    $stroke   Ring thickness.
     */
    public function __construct(
        public array $segments,
        public int $size = 36,
        public int $stroke = 6,
    ) {
    }

    public function render(): string
    {
        if ([] === $this->segments) {
            return '';
        }

        $total = 0;
        foreach ($this->segments as $seg) {
            $total += $seg['value'];
        }

        if (0 === $total) {
            return '';
        }

        $radius = ($this->size - $this->stroke) / 2;
        $circumference = 2 * pi() * $radius;
        $cx = $this->size / 2;
        $cy = $this->size / 2;

        $circles = '';
        $offset = 0.0;

        foreach ($this->segments as $seg) {
            $pct = $seg['value'] / $total;
            $dashLen = round($pct * $circumference, 2);
            $gapLen = round($circumference - $dashLen, 2);

            $circles .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $radius . '"'
                . ' fill="none" stroke="' . $seg['color'] . '"'
                . ' stroke-width="' . $this->stroke . '"'
                . ' vector-effect="non-scaling-stroke"'
                . ' stroke-dasharray="' . $dashLen . ' ' . $gapLen . '"'
                . ' stroke-dashoffset="' . round(-$offset, 2) . '"'
                . ' transform="rotate(-90 ' . $cx . ' ' . $cy . ')"'
                . '/>';

            $offset += $dashLen;
        }

        return '<svg class="m-chart m-chart--donut" width="' . $this->size . '" height="' . $this->size
            . '" viewBox="0 0 ' . $this->size . ' ' . $this->size
            . '" xmlns="http://www.w3.org/2000/svg">'
            . $circles
            . '</svg>';
    }
}
