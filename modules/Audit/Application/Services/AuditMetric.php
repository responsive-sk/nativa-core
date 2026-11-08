<?php

declare(strict_types = 1);

namespace Modules\Audit\Application\Services;

use Modules\Audit\Infrastructure\Persistence\Statement;
use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Charts\Bar;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Domain\Model\Trend;
use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;

/**
 * AuditMetric — aggregated admin action metric cards for the dashboard.
 *
 * Registers 1 card:
 * 1. Audit — admin actions in period + trend + top actions bar chart
 *
 * Data sources: audit.db → audit_log (created_at, action).
 */
#[AsMetricProvider(priority: 60)]
final readonly class AuditMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $pdo = DatabaseConnectionManager::instance()->connection('audit');

            $total = $this->countInPeriod($pdo, $period->sqlInterval());
            $previous = $this->countBetweenPeriods(
                $pdo,
                $period->previousSqlInterval(),
                $period->sqlInterval(),
            );

            $trend = Trend::make($total, $previous);

            $topActions = $this->topActions($pdo, $period->sqlInterval());
            $bar = new Bar($topActions);

            CardboardRegistry::register(
                Card::make('audit', 'Admin Actions', $total)
                    ->withChart($bar)
                    ->withTrend($trend)
                    ->withSubtitle('in ' . $period->label())
                    ->withActions(
                        CardLink::make('View Log', '/mark/audit'),
                    )
                    ->withPriority(55),
            );
        } catch (\Throwable) {
            // Database not ready — skip silently.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    private function countInPeriod(\PDO $pdo, string $interval): int
    {
        $stmt = Statement::prepare($pdo,
            'SELECT COUNT(*) FROM audit_log WHERE created_at >= datetime(\'now\', :interval)',
        );
        $stmt->execute([':interval' => $interval]);

        return (int) $stmt->fetchColumn();
    }

    private function countBetweenPeriods(\PDO $pdo, string $fromInterval, string $toInterval): int
    {
        $stmt = Statement::prepare($pdo,
            'SELECT COUNT(*) FROM audit_log WHERE created_at >= datetime(\'now\', :from) AND created_at < datetime(\'now\', :to)',
        );
        $stmt->execute([':from' => $fromInterval, ':to' => $toInterval]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Top 5 actions by count in the period, for the bar chart.
     *
     * @return list<array{label: string, value: int, color: string}>
     */
    private function topActions(\PDO $pdo, string $interval): array
    {
        $stmt = Statement::prepare($pdo,
            'SELECT action, COUNT(*) as cnt FROM audit_log WHERE created_at >= datetime(\'now\', :interval) GROUP BY action ORDER BY cnt DESC LIMIT 5',
        );
        $stmt->execute([':interval' => $interval]);

        $colors = [
            'var(--m-primary)',
            'var(--m-info)',
            'var(--m-success)',
            'var(--m-warning)',
            'var(--m-muted)',
        ];

        $bars = [];
        $i = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $bars[] = [
                'label' => (string) $row['action'],
                'value' => (int) $row['cnt'],
                'color' => $colors[$i % count($colors)],
            ];
            $i++;
        }

        return $bars;
    }
}
