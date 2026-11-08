<?php

declare(strict_types = 1);

namespace Modules\Outbox\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Outbox\Infrastructure\Persistence\Migrations\OutboxDatabaseMigrator;
use Modules\Outbox\Infrastructure\Persistence\Repositories\SqliteOutboxRepository;
use Modules\Outbox\Infrastructure\Persistence\Statement;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * OutboxAdminListTest — the listing API the admin pages drive, beside
 * the code it covers (module test contract: drives the INSTALLED copy,
 * skips on a kernel-only tree). Throwaway SQLite file, never a real
 * module database.
 */
final class OutboxAdminListTest extends TestCase
{
    private string $dbFile;

    private PDO $pdo;

    private SqliteOutboxRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Outbox')) {
            $this->markTestSkipped('Outbox is not installed.');
        }

        $this->dbFile = sys_get_temp_dir() . '/outbox-admin-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $null = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new OutboxDatabaseMigrator($this->pdo))->run($null);

        $this->repository = new SqliteOutboxRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        unset($this->repository, $this->pdo);

        if (isset($this->dbFile) && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function listsNewestFirstWithStatusFilter(): void
    {
        $this->seed('pending', '2026-01-01 10:00:00');
        $this->seed('processed', '2026-01-01 12:00:00');
        $this->seed('failed', '2026-01-01 11:00:00');

        $all = $this->repository->listMessages();

        $this->assertCount(3, $all);
        $this->assertSame('processed', $all[0]->status());
        $this->assertSame('failed', $all[1]->status());
        $this->assertSame('pending', $all[2]->status());

        $this->assertSame(3, $this->repository->countTotal());
        $this->assertSame(1, $this->repository->countTotal('failed'));

        $failed = $this->repository->listMessages('failed');
        $this->assertCount(1, $failed);
        $this->assertSame('failed', $failed[0]->status());

        $this->assertSame([], $this->repository->listMessages('no-such-status'));
    }

    private function seed(string $status, string $createdAt): void
    {
        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO outbox_messages (id, event_type, event_payload, status, attempts, max_attempts, created_at)'
            . ' VALUES (:id, :type, :payload, :status, 0, 5, :created)',
        );
        $stmt->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':type' => 'article.published',
            ':payload' => '{}',
            ':status' => $status,
            ':created' => $createdAt,
        ]);
    }
}
