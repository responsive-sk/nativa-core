<?php

declare(strict_types = 1);

namespace Modules\Pages\Application\Services;

use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Charts\Donut;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Core\Interfaces\HTTP\ContentServices;

/**
 * PagesMetric — aggregated pages metric card for the dashboard.
 *
 * Registers 1 card:
 * 1. Pages — total count (SK + EN) + donut chart + action links
 *
 * Data source: filesystem → storage/pages/{sk,en}/.
 */
#[AsMetricProvider(priority: 50)]
final readonly class PagesMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        $crud = new PageCrudService();
        $locales = ContentServices::mapper()->locales();
        $pageSk = count($crud->list($locales[0]));
        $pageEn = count($crud->list($locales[1]));
        $total = $pageSk + $pageEn;

        $donut = new Donut([
            ['value' => $pageSk, 'color' => 'var(--m-primary)'],
            ['value' => $pageEn, 'color' => 'var(--m-info)'],
        ]);

        CardboardRegistry::register(
            Card::make('pages', 'Pages', $total)
                ->withChart($donut)
                ->withSubtitle($pageSk . ' SK · ' . $pageEn . ' EN')
                ->withActions(
                    CardLink::make('List', '/mark/pages'),
                    CardLink::make('New', '/mark/pages/create'),
                )
                ->withPriority(50),
        );
    }
}
