<?php

declare(strict_types = 1);

namespace Modules\Comments\Application\Services;

use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Charts\Donut;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Domain\Model\Trend;
use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Comments\Domain\Entities\Comment;
use Modules\Comments\Infrastructure\Persistence\Statement;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;

/**
 * CommentsMetric — aggregated comment metric cards for the dashboard.
 *
 * Registers 1 card:
 * 1. Comments — total in period + trend + breakdown by status (pending/approved/rejected) via Donut
 *
 * Data sources: comments.db → comments (created_at, status).
 */
#[AsMetricProvider(priority: 80)]
final readonly class CommentsMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $pdo = DatabaseConnectionManager::instance()->connection('comments');

            $total = $this->countInPeriod($pdo, $period->sqlInterval());
            $previous = $this->countBetweenPeriods(
                $pdo,
                $period->previousSqlInterval(),
                $period->sqlInterval(),
            );

            $trend = Trend::make($total, $previous);

            $statusCounts = $this->countByStatus($pdo, $period->sqlInterval());
            $donut = new Donut([
                ['value' => $statusCounts[Comment::STATUS_PENDING], 'color' => 'var(--m-warning)'],
                ['value' => $statusCounts[Comment::STATUS_APPROVED], 'color' => 'var(--m-success)'],
                ['value' => $statusCounts[Comment::STATUS_REJECTED], 'color' => 'var(--m-error)'],
            ]);

            $breakdown = $statusCounts[Comment::STATUS_PENDING] . ' ' . Comment::STATUS_PENDING . ' · '
                . $statusCounts[Comment::STATUS_APPROVED] . ' ' . Comment::STATUS_APPROVED . ' · '
                . $statusCounts[Comment::STATUS_REJECTED] . ' ' . Comment::STATUS_REJECTED;

            CardboardRegistry::register(
                Card::make('comments', 'Comments', $total)
                    ->withChart($donut)
                    ->withTrend($trend)
                    ->withSubtitle('in ' . $period->label() . ' — ' . $breakdown)
                    ->withActions(
                        CardLink::make('Moderate', '/mark/comments'),
                    )
                    ->withPriority(75),
            );
        } catch (\Throwable) {
            // Database not ready — skip silently.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    private function countInPeriod(\PDO $pdo, string $interval): int
    {
        $stmt = Statement::prepare($pdo,
            'SELECT COUNT(*) FROM comments WHERE created_at >= datetime(\'now\', :interval)',
        );
        $stmt->execute([':interval' => $interval]);

        return (int) $stmt->fetchColumn();
    }

    private function countBetweenPeriods(\PDO $pdo, string $fromInterval, string $toInterval): int
    {
        $stmt = Statement::prepare($pdo,
            'SELECT COUNT(*) FROM comments WHERE created_at >= datetime(\'now\', :from) AND created_at < datetime(\'now\', :to)',
        );
        $stmt->execute([':from' => $fromInterval, ':to' => $toInterval]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function countByStatus(\PDO $pdo, string $interval): array
    {
        $stmt = Statement::prepare($pdo,
            'SELECT status, COUNT(*) as cnt FROM comments WHERE created_at >= datetime(\'now\', :interval) GROUP BY status',
        );
        $stmt->execute([':interval' => $interval]);

        $counts = [
            Comment::STATUS_PENDING => 0,
            Comment::STATUS_APPROVED => 0,
            Comment::STATUS_REJECTED => 0,
        ];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $status = (string) $row['status'];
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['cnt'];
            }
        }

        return $counts;
    }
}
