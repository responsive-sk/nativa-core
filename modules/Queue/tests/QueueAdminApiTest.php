<?php

declare(strict_types = 1);

namespace Modules\Queue\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Queue\Application\Services\DatabaseQueue;
use Modules\Queue\Application\Services\HandlerRegistry;
use Modules\Queue\Infrastructure\Persistence\Migrations\QueueDatabaseMigrator;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * QueueAdminApiTest — the read/retry API the admin pages drive, beside
 * the code it covers (module test contract: drives the INSTALLED copy,
 * skips on a kernel-only tree). Throwaway SQLite file, never a real
 * module database.
 */
final class QueueAdminApiTest extends TestCase
{
    private string $dbFile;

    private PDO $pdo;

    private DatabaseQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Queue')) {
            $this->markTestSkipped('Queue is not installed.');
        }

        $this->dbFile = sys_get_temp_dir() . '/queue-admin-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $null = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new QueueDatabaseMigrator($this->pdo))->run($null);

        $handlers = new HandlerRegistry();
        $handlers->register('ok.handler', static fn (): null => null);
        $handlers->register('bad.handler', static function (): void {
            throw new RuntimeException('nope');
        });

        $this->queue = new DatabaseQueue($this->pdo, $handlers);
    }

    protected function tearDown(): void
    {
        unset($this->queue, $this->pdo);

        if (isset($this->dbFile) && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function listsNewestFirstWithFilterAndState(): void
    {
        $first = $this->queue->push('ok.handler', [], queue: 'mail');
        $this->queue->push('ok.handler', [], queue: 'default');
        $delayed = $this->queue->push('ok.handler', [], delaySeconds: 3600);
        $this->queue->reserve(); // holds one row: state reserved

        $all = $this->queue->listJobs();

        $this->assertCount(3, $all);
        // Newest first: the delayed push was last.
        $this->assertSame($delayed, $all[0]['id']);
        $this->assertSame('delayed', $all[0]['state']);

        $states = array_column($all, 'state');
        sort($states);
        $this->assertSame(['delayed', 'pending', 'reserved'], $states);

        $mail = $this->queue->listJobs('mail');
        $this->assertCount(1, $mail);
        $this->assertSame($first, $mail[0]['id']);

        $this->assertSame([], $this->queue->listJobs('no-such-queue'));
    }

    #[Test]
    public function findJobReturnsRowWithPayload(): void
    {
        $id = $this->queue->push('ok.handler', ['to' => 'a@example.com']);

        $found = $this->queue->findJob($id);

        $this->assertNotNull($found);
        $this->assertSame('ok.handler', $found['handler']);
        $this->assertSame('pending', $found['state']);
        $this->assertSame(['to' => 'a@example.com'], json_decode($found['payload'], true));

        $this->assertNull($this->queue->findJob('missing-id'));
    }

    #[Test]
    public function retryRoundTripsFailedToPending(): void
    {
        $this->queue->push('bad.handler', ['n' => 1], maxAttempts: 1);
        $this->queue->work();

        $failed = $this->queue->listFailed();
        $this->assertCount(1, $failed);
        $this->assertSame('bad.handler', $failed[0]['handler']);
        $this->assertSame('nope', $failed[0]['error']);

        $id = $failed[0]['id'];
        $found = $this->queue->findFailed($id);
        $this->assertNotNull($found);
        $this->assertSame(['n' => 1], json_decode($found['payload'], true));

        $this->assertTrue($this->queue->retryFailed($id));

        $this->assertSame([], $this->queue->listFailed());
        $this->assertNull($this->queue->findFailed($id));

        $pending = $this->queue->findJob($id);
        $this->assertNotNull($pending);
        $this->assertSame($id, $pending['id'], 'retry keeps the id');
        $this->assertSame(0, $pending['attempts'], 'retry resets attempts');
        $this->assertSame('pending', $pending['state']);

        $this->assertFalse($this->queue->retryFailed('missing-id'));
    }

    #[Test]
    public function deleteFailedDropsTheRow(): void
    {
        $this->queue->push('bad.handler', [], maxAttempts: 1);
        $this->queue->work();

        $id = $this->queue->listFailed()[0]['id'];

        $this->assertTrue($this->queue->deleteFailed($id));
        $this->assertSame([], $this->queue->listFailed());
        $this->assertFalse($this->queue->deleteFailed($id));
    }

    #[Test]
    public function deletingAReservedJobDropsIt(): void
    {
        $id = $this->queue->push('ok.handler');
        $this->assertNotNull($this->queue->reserve());

        $this->queue->delete($id);

        $this->assertNull($this->queue->findJob($id));
        $this->assertSame([], $this->queue->listJobs());
    }
}
