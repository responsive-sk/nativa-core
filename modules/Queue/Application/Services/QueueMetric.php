<?php

declare(strict_types = 1);

namespace Modules\Queue\Application\Services;

use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Domain\Model\Trend;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Queue\Infrastructure\Persistence\Statement;

/**
 * QueueMetric — queued-job stat card for the admin dashboard.
 *
 * Registers 1 card:
 * 1. Queue Jobs — reservable jobs right now + reserved/failed subtitle +
 *    pushed-in-period trend
 *
 * Data sources: queue.db → jobs (reserved_at, available_at, created_at),
 * failed_jobs (row count). The reservable definition mirrors
 * DatabaseQueue::findAvailable() exactly (PHP-stamped `now`, not
 * SQLite `datetime('now')`, because reservations are written with PHP
 * stamps) — the card reports what `reserve()` would hand out.
 */
#[AsMetricProvider(priority: 68)]
final readonly class QueueMetric implements MetricContract
{
    public function __construct(
        private ?ConnectionInterface $connection = null,
    ) {
    }

    public function registerCards(Period $period): void
    {
        try {
            $connection = $this->connection ?? DatabaseConnectionManager::instance()->connect('queue');

            $pending = $this->countReservable($connection);
            $reserved = $this->countReserved($connection);
            $failed = $this->countFailed($connection);

            $pushed = $this->countPushedSince($connection, $period->sqlInterval());
            $previous = $this->countPushedBetween(
                $connection,
                $period->previousSqlInterval(),
                $period->sqlInterval(),
            );

            CardboardRegistry::register(
                Card::make('queue', 'Queue Jobs', $pending)
                    ->withSubtitle("{$reserved} reserved · {$failed} failed")
                    ->withTrend(Trend::make($pushed, $previous))
                    ->withActions(CardLink::make('View Jobs', '/mark/queue'))
                    ->withPriority(68),
            );
        } catch (\Throwable) {
            // Database not ready — skip silently.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    /**
     * Jobs `reserve()` would hand out now (mirrors findAvailable()).
     */
    private function countReservable(ConnectionInterface $connection): int
    {
        $stmt = Statement::prepare($connection,
            'SELECT COUNT(*) FROM jobs WHERE reserved_at IS NULL AND available_at <= :now',
        );
        $stmt->execute([':now' => date('Y-m-d H:i:s')]);

        return (int) $stmt->fetchColumn();
    }

    private function countReserved(ConnectionInterface $connection): int
    {
        return (int) Statement::query($connection, 'SELECT COUNT(*) FROM jobs WHERE reserved_at IS NOT NULL')->fetchColumn();
    }

    private function countFailed(ConnectionInterface $connection): int
    {
        return (int) Statement::query($connection, 'SELECT COUNT(*) FROM failed_jobs')->fetchColumn();
    }

    private function countPushedSince(ConnectionInterface $connection, string $interval): int
    {
        $stmt = Statement::prepare($connection,
            'SELECT COUNT(*) FROM jobs WHERE created_at >= datetime(\'now\', :interval)',
        );
        $stmt->execute([':interval' => $interval]);

        return (int) $stmt->fetchColumn();
    }

    private function countPushedBetween(ConnectionInterface $connection, string $fromInterval, string $toInterval): int
    {
        $stmt = Statement::prepare($connection,
            'SELECT COUNT(*) FROM jobs WHERE created_at >= datetime(\'now\', :from) AND created_at < datetime(\'now\', :to)',
        );
        $stmt->execute([':from' => $fromInterval, ':to' => $toInterval]);

        return (int) $stmt->fetchColumn();
    }
}
