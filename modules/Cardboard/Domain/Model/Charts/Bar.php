<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Domain\Model\Charts;

use Modules\Cardboard\Domain\Contracts\ChartContract;

/**
 * Bar — inline SVG categorical comparison chart.
 *
 * Renders vertical <rect> elements normalized to the tallest bar.
 * No JS, no external resources, CSP-safe.
 */
final readonly class Bar implements ChartContract
{
    /**
     * @param list<array{label: string, value: int, color: string}> $bars   Bar data.
     * @param int                                                   $width  SVG viewport width.
     * @param int                                                   $height SVG viewport height.
     */
    public function __construct(
        public array $bars,
        public int $width = 80,
        public int $height = 32,
    ) {
    }

    public function render(): string
    {
        if ([] === $this->bars) {
            return '';
        }

        $maxVal = 0;
        foreach ($this->bars as $bar) {
            if ($bar['value'] > $maxVal) {
                $maxVal = $bar['value'];
            }
        }

        if (0 === $maxVal) {
            return '';
        }

        $count = count($this->bars);
        $gap = 2;
        $barWidth = max(1, (int) round(($this->width - $gap * ($count + 1)) / $count));

        $rects = '';
        foreach ($this->bars as $i => $bar) {
            $barHeight = max(1, (int) round(($bar['value'] / $maxVal) * $this->height));
            $x = $gap + $i * ($barWidth + $gap);
            $y = $this->height - $barHeight;

            $rects .= '<rect x="' . $x . '" y="' . $y . '"'
                . ' width="' . $barWidth . '" height="' . $barHeight . '"'
                . ' rx="1" fill="' . $bar['color'] . '"/>';
        }

        return '<svg class="m-chart m-chart--bars" width="' . $this->width . '" height="' . $this->height
            . '" viewBox="0 0 ' . $this->width . ' ' . $this->height
            . '" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">'
            . $rects
            . '</svg>';
    }
}
