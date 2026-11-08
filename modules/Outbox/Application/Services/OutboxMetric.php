<?php

declare(strict_types = 1);

namespace Modules\Outbox\Application\Services;

use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Domain\Model\Trend;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Outbox\Infrastructure\Persistence\Repositories\SqliteOutboxRepository;
use Modules\Outbox\Infrastructure\Persistence\Statement;
use PDO;

/**
 * OutboxMetric — outbox message stat card for the admin dashboard.
 *
 * Registers 1 card:
 * 1. Outbox — messages needing attention (pending + processing) +
 *    processed/recently-failed subtitle + processed-in-period trend
 *
 * Data sources: outbox.db → outbox_messages (status, processed_at,
 * last_attempt_at). "Failed in period" is proxied through
 * `last_attempt_at` — failed rows carry no failed_at stamp, and the last
 * attempt is when the failure was confirmed. Never-attempted failed rows
 * (NULL stamp) stay out of the period count but remain in the
 * attention value while their status needs work.
 */
#[AsMetricProvider(priority: 67)]
final readonly class OutboxMetric implements MetricContract
{
    public function __construct(
        private ?PDO $pdo = null,
    ) {
    }

    public function registerCards(Period $period): void
    {
        try {
            $pdo = $this->pdo ?? DatabaseConnectionManager::instance()->connection('outbox');
            $repository = new SqliteOutboxRepository($pdo);

            $counts = $repository->countByStatus();
            $attention = $counts['pending'] + $counts['processing'];

            $processed = $this->countProcessedSince($pdo, $period->sqlInterval());
            $processedBefore = $this->countProcessedBetween(
                $pdo,
                $period->previousSqlInterval(),
                $period->sqlInterval(),
            );
            $failed = $this->countFailedSince($pdo, $period->sqlInterval());

            CardboardRegistry::register(
                Card::make('outbox', 'Outbox', $attention)
                    ->withSubtitle("{$processed} processed · {$failed} failed in {$period->label()}")
                    ->withTrend(Trend::make($processed, $processedBefore))
                    ->withActions(CardLink::make('View Messages', '/mark/outbox'))
                    ->withPriority(67),
            );
        } catch (\Throwable) {
            // Database not ready — skip silently.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    private function countProcessedSince(PDO $pdo, string $interval): int
    {
        $stmt = Statement::prepare($pdo,
            'SELECT COUNT(*) FROM outbox_messages WHERE status = \'processed\' AND processed_at >= datetime(\'now\', :interval)',
        );
        $stmt->execute([':interval' => $interval]);

        return (int) $stmt->fetchColumn();
    }

    private function countProcessedBetween(PDO $pdo, string $fromInterval, string $toInterval): int
    {
        $stmt = Statement::prepare($pdo,
            'SELECT COUNT(*) FROM outbox_messages WHERE status = \'processed\' AND processed_at >= datetime(\'now\', :from) AND processed_at < datetime(\'now\', :to)',
        );
        $stmt->execute([':from' => $fromInterval, ':to' => $toInterval]);

        return (int) $stmt->fetchColumn();
    }

    private function countFailedSince(PDO $pdo, string $interval): int
    {
        $stmt = Statement::prepare($pdo,
            'SELECT COUNT(*) FROM outbox_messages WHERE status = \'failed\' AND last_attempt_at >= datetime(\'now\', :interval)',
        );
        $stmt->execute([':interval' => $interval]);

        return (int) $stmt->fetchColumn();
    }
}
