<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Blog\Infrastructure\Persistence\Statement;
use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Charts\Donut;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Domain\Model\Trend;
use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;

/**
 * BlogMetric — aggregated blog views/articles metric cards for the dashboard.
 *
 * Registers 2 cards:
 * 1. Article Views — total views in period + trend vs previous period
 * 2. Articles — total count (SK + EN) + published-in-period trend + donut chart
 *
 * Data sources: blog.db → article_views (viewed_at), filesystem → storage/blog/{sk,en}/ (frontmatter date).
 */
#[AsMetricProvider(priority: 90)]
final readonly class BlogMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $this->registerViewsCard($period);
        } catch (\Throwable) {
            // blog.db not ready — skip views card.
            // @mago-expect lint:no-empty-catch-clause
        }

        try {
            $this->registerArticlesCard($period);
        } catch (\Throwable) {
            // Blog articles not available — skip articles card.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    private function registerViewsCard(Period $period): void
    {
        $pdo = DatabaseConnectionManager::instance()->connection('blog');

        $viewsInPeriod = $this->countViewsInPeriod($pdo, $period->sqlInterval());
        $viewsPreviously = $this->countViewsBetweenPeriods(
            $pdo,
            $period->previousSqlInterval(),
            $period->sqlInterval(),
        );

        $trend = Trend::make($viewsInPeriod, $viewsPreviously);

        CardboardRegistry::register(
            Card::make('views', 'Article Views', $viewsInPeriod)
                ->withTrend($trend)
                ->withSubtitle('in ' . $period->label())
                ->withPriority(85),
        );
    }

    private function registerArticlesCard(Period $period): void
    {
        $crud = new BlogCrudService();
        $locales = ContentServices::mapper()->locales();
        $articleSk = count($crud->list($locales[0]));
        $articleEn = count($crud->list($locales[1]));
        $total = $articleSk + $articleEn;

        [$publishedNow, $publishedBefore] = $this->publishedInWindows($crud, $period);
        $trend = Trend::make($publishedNow, $publishedBefore);

        $donut = new Donut([
            ['value' => $articleSk, 'color' => 'var(--m-primary)'],
            ['value' => $articleEn, 'color' => 'var(--m-info)'],
        ]);

        CardboardRegistry::register(
            Card::make('articles', 'Articles', $total)
                ->withChart($donut)
                ->withTrend($trend)
                ->withSubtitle('published in ' . $period->label() . ' — ' . $articleSk . ' SK · ' . $articleEn . ' EN')
                ->withActions(
                    CardLink::make('List', '/mark/articles'),
                    CardLink::make('New', '/mark/articles/create'),
                )
                ->withPriority(80),
        );
    }

    /**
     * Count unique articles published in the current window vs the previous
     * window of equal length, from the frontmatter date.
     *
     * A translation pair (the same slug in sk/ and en/) is one logical
     * article — the earliest date wins so it is never counted twice.
     *
     * @return array{0: int, 1: int} [current window, previous window]
     */
    private function publishedInWindows(BlogCrudService $crud, Period $period): array
    {
        $days = $period->days();
        $currentStartTs = strtotime('today -' . ($days - 1) . ' days');
        $currentStart = false !== $currentStartTs ? date('Y-m-d', $currentStartTs) : date('Y-m-d');
        $previousStartTs = strtotime('today -' . ($days * 2 - 1) . ' days');
        $previousStart = false !== $previousStartTs ? date('Y-m-d', $previousStartTs) : date('Y-m-d');

        $published = [];
        foreach (ContentServices::mapper()->locales() as $locale) {
            foreach ($crud->list($locale) as $article) {
                $date = $article['date'];
                if ('' === $date) {
                    continue;
                }
                $slug = $article['slug'];
                $published[$slug] = isset($published[$slug]) ? min($published[$slug], $date) : $date;
            }
        }

        $current = 0;
        $previous = 0;
        foreach ($published as $date) {
            if ($date >= $currentStart) {
                $current++;
            } elseif ($date >= $previousStart) {
                $previous++;
            }
        }

        return [$current, $previous];
    }

    /**
     * Count article views in the given period.
     */
    private function countViewsInPeriod(\PDO $pdo, string $interval): int
    {
        $stmt = Statement::prepare($pdo,
            'SELECT COUNT(*) FROM article_views WHERE viewed_at >= datetime(\'now\', :interval)',
        );
        $stmt->execute([':interval' => $interval]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Count article views between two period boundaries.
     */
    private function countViewsBetweenPeriods(\PDO $pdo, string $fromInterval, string $toInterval): int
    {
        $stmt = Statement::prepare($pdo,
            'SELECT COUNT(*) FROM article_views WHERE viewed_at >= datetime(\'now\', :from) AND viewed_at < datetime(\'now\', :to)',
        );
        $stmt->execute([':from' => $fromInterval, ':to' => $toInterval]);

        return (int) $stmt->fetchColumn();
    }
}
