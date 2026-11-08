<?php

declare(strict_types = 1);

namespace Modules\Users\Application\Services;

use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Domain\Model\Trend;
use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Users\Infrastructure\Persistence\Statement;

/**
 * UsersMetric — aggregated user/session metric cards for the dashboard.
 *
 * Registers 2 cards:
 * 1. Users — total count + new in period (Trend)
 * 2. Sessions — active sessions (last 30min) + new in period (Trend)
 *
 * Data sources: users.db → users (created_at), sessions (created_at, last_activity).
 */
#[AsMetricProvider(priority: 100)]
final readonly class UsersMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $pdo = DatabaseConnectionManager::instance()->connection('users');

            $this->registerUsersCard($pdo, $period);
            $this->registerSessionsCard($pdo, $period);
        } catch (\Throwable) {
            // Database not ready — skip silently.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    private function registerUsersCard(\PDO $pdo, Period $period): void
    {
        $totalStmt = Statement::query($pdo, 'SELECT COUNT(*) FROM users');
        $total = (int) $totalStmt->fetchColumn();

        $newInPeriod = $this->countInPeriod($pdo, 'users', 'created_at', $period->sqlInterval());
        $newPreviously = $this->countBetweenPeriods(
            $pdo,
            'users',
            'created_at',
            $period->previousSqlInterval(),
            $period->sqlInterval(),
        );

        $trend = Trend::make($newInPeriod, $newPreviously);

        CardboardRegistry::register(
            Card::make('users', 'Users', $total)
                ->withTrend($trend)
                ->withSubtitle('new in ' . $period->label())
                ->withActions(
                    CardLink::make('List', '/mark/users'),
                    CardLink::make('New', '/mark/users/create'),
                )
                ->withPriority(100),
        );
    }

    private function registerSessionsCard(\PDO $pdo, Period $period): void
    {
        $activeStmt = Statement::query($pdo,
            'SELECT COUNT(*) FROM sessions WHERE last_activity > datetime("now", "-30 minutes")',
        );
        $active = (int) $activeStmt->fetchColumn();

        $newInPeriod = $this->countInPeriod($pdo, 'sessions', 'created_at', $period->sqlInterval());
        $newPreviously = $this->countBetweenPeriods(
            $pdo,
            'sessions',
            'created_at',
            $period->previousSqlInterval(),
            $period->sqlInterval(),
        );

        $trend = Trend::make($newInPeriod, $newPreviously);

        CardboardRegistry::register(
            Card::make('sessions', 'Sessions', $active)
                ->withTrend($trend)
                ->withSubtitle('new in ' . $period->label())
                ->withPriority(90),
        );
    }

    /**
     * Count rows where column >= now + interval.
     */
    private function countInPeriod(\PDO $pdo, string $table, string $column, string $interval): int
    {
        $stmt = Statement::prepare($pdo,
            "SELECT COUNT(*) FROM {$table} WHERE {$column} >= datetime('now', :interval)",
        );
        $stmt->execute([':interval' => $interval]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Count rows where column >= now + fromInterval AND column < now + toInterval.
     *
     * This gives the count for the period between fromInterval and toInterval ago.
     */
    private function countBetweenPeriods(
        \PDO $pdo,
        string $table,
        string $column,
        string $fromInterval,
        string $toInterval,
    ): int {
        $stmt = Statement::prepare($pdo,
            "SELECT COUNT(*) FROM {$table} WHERE {$column} >= datetime('now', :from) AND {$column} < datetime('now', :to)",
        );
        $stmt->execute([':from' => $fromInterval, ':to' => $toInterval]);

        return (int) $stmt->fetchColumn();
    }
}
