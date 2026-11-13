<?php

declare(strict_types = 1);

namespace Modules\Media\Application\Services;

use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Domain\Model\Trend;
use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Media\Infrastructure\Persistence\Statement;

/**
 * MediaMetric — aggregated media uploads metric cards for the dashboard.
 *
 * Registers 1 card:
 * 1. Media — total files + new uploads in period + trend
 *
 * Data sources: media.db → assets (created_at).
 */
#[AsMetricProvider(priority: 70)]
final readonly class MediaMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $connection = DatabaseConnectionManager::instance()->connect('media');

            $totalStmt = Statement::query($connection, 'SELECT COUNT(*) FROM assets');
            $total = (int) $totalStmt->fetchColumn();
            $newInPeriod = $this->countInPeriod($connection, $period->sqlInterval());
            $newPreviously = $this->countBetweenPeriods(
                $connection,
                $period->previousSqlInterval(),
                $period->sqlInterval(),
            );

            $trend = Trend::make($newInPeriod, $newPreviously);

            CardboardRegistry::register(
                Card::make('media', 'Media files', $total)
                    ->withTrend($trend)
                    ->withSubtitle('uploads in ' . $period->label())
                    ->withActions(
                        CardLink::make('List', '/mark/media'),
                        CardLink::make('Upload', '/mark/media/upload'),
                    )
                    ->withPriority(65),
            );
        } catch (\Throwable) {
            // Database not ready — skip silently.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    private function countInPeriod(ConnectionInterface $connection, string $interval): int
    {
        $stmt = Statement::prepare($connection,
            'SELECT COUNT(*) FROM assets WHERE created_at >= datetime(\'now\', :interval)',
        );
        $stmt->execute([':interval' => $interval]);

        return (int) $stmt->fetchColumn();
    }

    private function countBetweenPeriods(ConnectionInterface $connection, string $fromInterval, string $toInterval): int
    {
        $stmt = Statement::prepare($connection,
            'SELECT COUNT(*) FROM assets WHERE created_at >= datetime(\'now\', :from) AND created_at < datetime(\'now\', :to)',
        );
        $stmt->execute([':from' => $fromInterval, ':to' => $toInterval]);

        return (int) $stmt->fetchColumn();
    }
}
