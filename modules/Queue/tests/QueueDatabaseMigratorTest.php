<?php

declare(strict_types = 1);

namespace Modules\Queue\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Queue\Infrastructure\Persistence\Migrations\QueueDatabaseMigrator;
use Modules\Queue\Infrastructure\Persistence\Statement;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * QueueDatabaseMigratorTest — beside the code it covers (module test
 * contract: drives the INSTALLED copy, skips on a kernel-only tree).
 *
 * The migrator is pure PDO, so the test runs it against a throwaway
 * SQLite file — never a real module database.
 */
final class QueueDatabaseMigratorTest extends TestCase
{
    private string $dbFile;

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Queue')) {
            $this->markTestSkipped('Queue is not installed.');
        }

        $this->dbFile = sys_get_temp_dir() . '/queue-migrator-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);

        if (isset($this->dbFile) && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function createsBothTablesIdempotently(): void
    {
        $output = new class implements CliOutput {
            /** @var list<string> */
            public array $lines = [];

            public function writeln(string $message): void
            {
                $this->lines[] = $message;
            }
        };

        $migrator = new QueueDatabaseMigrator($this->pdo);
        $migrator->run($output);
        // Re-running is safe (the `bin/cms migrate` contract).
        $migrator->run($output);

        $tables = Statement::query(
            $this->pdo,
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('jobs', 'failed_jobs')",
        )->fetchAll(PDO::FETCH_COLUMN);

        sort($tables);

        $this->assertSame(['failed_jobs', 'jobs'], $tables);

        $columns = Statement::query($this->pdo, 'PRAGMA table_info(jobs)')
            ->fetchAll(PDO::FETCH_COLUMN, 1);

        foreach (['id', 'queue', 'handler', 'payload', 'attempts', 'max_attempts', 'reserved_at', 'available_at', 'created_at'] as $column) {
            $this->assertContains($column, $columns, "jobs.{$column} is missing");
        }

        $this->assertNotEmpty($output->lines);
    }
}
