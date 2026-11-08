<?php

declare(strict_types = 1);

namespace Modules\Forms\Application\Services;

use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Domain\Model\Trend;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Infrastructure\Persistence\Statement;

/**
 * FormsMetric — one dashboard card: submissions received in the period.
 *
 * Same shape as `CommentsMetric`, including the guard: Cardboard renders the
 * dashboard before every module has necessarily migrated, and a card that
 * throws takes the dashboard down. A missing database means no card.
 */
#[AsMetricProvider(priority: 85)]
final readonly class FormsMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $pdo = DatabaseConnectionManager::instance()->connection('forms');

            $total = $this->countInPeriod($pdo, $period->sqlInterval());
            $previous = $this->countBetweenPeriods(
                $pdo,
                $period->previousSqlInterval(),
                $period->sqlInterval(),
            );

            CardboardRegistry::register(
                Card::make('forms', 'Form submissions', $total)
                    ->withTrend(Trend::make($total, $previous))
                    ->withSubtitle('received in ' . $period->label())
                    ->withActions(CardLink::make('Open forms', FormsBadge::NAV_HREF))
                    ->withPriority(85),
            );
        } catch (\Throwable) {
            // Database not ready: the card is simply absent.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    private function countInPeriod(\PDO $pdo, string $interval): int
    {
        $stmt = Statement::prepare($pdo,
            'SELECT COUNT(*) FROM form_submissions WHERE created_at >= datetime(\'now\', :interval)',
        );
        $stmt->execute([':interval' => $interval]);

        return (int) $stmt->fetchColumn();
    }

    private function countBetweenPeriods(\PDO $pdo, string $fromInterval, string $toInterval): int
    {
        $stmt = Statement::prepare($pdo,
            'SELECT COUNT(*) FROM form_submissions'
            . ' WHERE created_at >= datetime(\'now\', :from) AND created_at < datetime(\'now\', :to)',
        );
        $stmt->execute([':from' => $fromInterval, ':to' => $toInterval]);

        return (int) $stmt->fetchColumn();
    }
}
