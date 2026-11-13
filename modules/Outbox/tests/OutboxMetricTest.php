<?php

declare(strict_types = 1);

namespace Modules\Outbox\Tests;

use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Outbox\Application\Services\OutboxMetric;
use Modules\Outbox\Infrastructure\Persistence\Migrations\OutboxDatabaseMigrator;
use Modules\Outbox\Infrastructure\Persistence\Statement;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * OutboxMetricTest — beside the code it covers (module test contract:
 * drives the INSTALLED copy, skips on a kernel-only tree).
 *
 * Seeds a throwaway SQLite file with one row per status and asserts the
 * registered card — never a real module database.
 */
final class OutboxMetricTest extends TestCase
{
    private string $dbFile;

    private PDO $pdo;

    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Outbox') || !class_exists(CardboardRegistry::class)) {
            $this->markTestSkipped('Outbox (or Cardboard) is not installed.');
        }

        $this->dbFile = sys_get_temp_dir() . '/outbox-metric-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->connection = new PdoConnection($this->pdo, 'outbox');

        $null = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new OutboxDatabaseMigrator($this->pdo))->run($null);

        CardboardRegistry::reset();
    }

    protected function tearDown(): void
    {
        if (class_exists(CardboardRegistry::class)) {
            CardboardRegistry::reset();
        }

        unset($this->connection, $this->pdo);

        if (isset($this->dbFile) && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function registersOutboxCardWithLiveCounts(): void
    {
        $this->seed('pending', null, null);
        $this->seed('pending', null, null);
        $this->seed('processing', null, null);
        $this->seed('processed', date('Y-m-d H:i:s'), null);
        $this->seed('processed', date('Y-m-d H:i:s'), null);
        // Old processed row: outside every 7d window, must not count.
        $this->seed('processed', date('Y-m-d H:i:s', strtotime('-60 days')), null);
        $this->seed('failed', null, date('Y-m-d H:i:s'));

        (new OutboxMetric($this->connection))->registerCards(Period::SEVEN_DAYS);

        $cards = CardboardRegistry::all();
        $this->assertCount(1, $cards);

        $card = $cards[0];
        $this->assertSame('outbox', $card->id);
        $this->assertSame('/mark/outbox', $card->actions[0]->href ?? null);
        $this->assertSame(3, $card->value, 'pending + processing need attention');
        $this->assertSame('2 processed · 1 failed in 7 days', $card->subtitle);
        $this->assertNotNull($card->trend);
    }

    #[Test]
    public function emptyDatabaseRegistersZeroCard(): void
    {
        (new OutboxMetric($this->connection))->registerCards(Period::SEVEN_DAYS);

        $cards = CardboardRegistry::all();
        $this->assertCount(1, $cards);
        $this->assertSame(0, $cards[0]->value);
        $this->assertSame('0 processed · 0 failed in 7 days', $cards[0]->subtitle);
    }

    private function seed(string $status, ?string $processedAt, ?string $lastAttemptAt): void
    {
        $stmt = Statement::prepare($this->connection,
            'INSERT INTO outbox_messages (id, event_type, event_payload, status, attempts, max_attempts, processed_at, last_attempt_at, created_at)'
            . ' VALUES (:id, :type, :payload, :status, 0, 3, :processed, :attempted, :created)',
        );
        $stmt->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':type' => 'article.published',
            ':payload' => '{}',
            ':status' => $status,
            ':processed' => $processedAt,
            ':attempted' => $lastAttemptAt,
            ':created' => date('Y-m-d H:i:s'),
        ]);
    }
}
