<?php

declare(strict_types = 1);

namespace Modules\Queue\Tests;

use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Queue\Application\Services\DatabaseQueue;
use Modules\Queue\Application\Services\HandlerRegistry;
use Modules\Queue\Application\Services\QueueMetric;
use Modules\Queue\Infrastructure\Persistence\Migrations\QueueDatabaseMigrator;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * QueueMetricTest — beside the code it covers (module test contract:
 * drives the INSTALLED copy, skips on a kernel-only tree).
 *
 * Seeds a throwaway SQLite file through the real DatabaseQueue paths
 * (push / reserve / failing work) and asserts the registered card —
 * never a real module database, never `storage/`.
 */
final class QueueMetricTest extends TestCase
{
    private string $dbFile;

    private PDO $pdo;

    private PdoConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Queue') || !class_exists(CardboardRegistry::class)) {
            $this->markTestSkipped('Queue (or Cardboard) is not installed.');
        }

        $this->dbFile = sys_get_temp_dir() . '/queue-metric-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $null = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new QueueDatabaseMigrator($this->pdo))->run($null);

        // The migrator keeps the raw handle; services take the contract.
        $this->connection = new PdoConnection($this->pdo, 'queue');

        CardboardRegistry::reset();
    }

    protected function tearDown(): void
    {
        if (class_exists(CardboardRegistry::class)) {
            CardboardRegistry::reset();
        }

        unset($this->pdo);

        if (isset($this->dbFile) && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function registersQueueCardWithLiveCounts(): void
    {
        $handlers = new HandlerRegistry();
        $handlers->register('ok.handler', static fn (): null => null);
        $handlers->register('bad.handler', static function (): void {
            throw new RuntimeException('nope');
        });

        $queue = new DatabaseQueue($this->connection, $handlers);
        $queue->push('ok.handler'); // pending
        $queue->push('ok.handler'); // pending
        $queue->push('ok.handler', [], delaySeconds: 3600); // future, not reservable
        $queue->push('ok.handler');

        // Hold one row so the card has something reserved to report.
        $this->assertNotNull($queue->reserve());

        $queue->push('bad.handler', [], maxAttempts: 1);
        $queue->work(limit: 10); // parks bad.handler in failed_jobs, processes pendings

        (new QueueMetric($this->connection))->registerCards(Period::SEVEN_DAYS);

        $cards = CardboardRegistry::all();
        $this->assertCount(1, $cards);

        $card = $cards[0];
        $this->assertSame('queue', $card->id);
        $this->assertSame('/mark/queue', $card->actions[0]->href ?? null);
        // After work(): the 2 pendings processed + deleted, the reserved
        // one still held, the delayed one waiting, 1 failed.
        $this->assertSame(0, $card->value);
        $this->assertSame('1 reserved · 1 failed', $card->subtitle);
        $this->assertNotNull($card->trend);
    }

    #[Test]
    public function emptyDatabaseRegistersZeroCard(): void
    {
        (new QueueMetric($this->connection))->registerCards(Period::SEVEN_DAYS);

        $cards = CardboardRegistry::all();
        $this->assertCount(1, $cards);
        $this->assertSame(0, $cards[0]->value);
        $this->assertSame('0 reserved · 0 failed', $cards[0]->subtitle);
    }
}
