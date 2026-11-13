<?php

declare(strict_types = 1);

namespace Modules\Queue\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;

/**
 * Queue Database Migrator — creates the queue.db schema.
 *
 * Tables: jobs (pending + reserved rows; the `attempts` column is
 * authoritative for the retry count), failed_jobs (exhausted or
 * unroutable rows with the error that sank them). Every statement is
 * idempotent (CREATE TABLE IF NOT EXISTS), so re-running via
 * `bin/cms migrate` is safe.
 *
 * Payloads are JSON-encoded handler args; ids are 32-char hex
 * (`bin2hex(random_bytes(16))`, the tree convention).
 */
#[AsMigrator(module: 'queue', table: 'jobs')]
final readonly class QueueDatabaseMigrator implements MigratorInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating queue.db...</info>');

        $this->createJobsTable();
        $this->createFailedJobsTable();
        $this->createIndexes();

        $output->writeln('<info>  ✓ Created tables: jobs, failed_jobs</info>');
    }

    private function createJobsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS jobs (
                    id VARCHAR(32) PRIMARY KEY,
                    queue VARCHAR(64) NOT NULL DEFAULT 'default',
                    handler VARCHAR(255) NOT NULL,
                    payload TEXT NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    max_attempts INTEGER NOT NULL DEFAULT 3,
                    reserved_at DATETIME,
                    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createFailedJobsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS failed_jobs (
                    id VARCHAR(32) PRIMARY KEY,
                    queue VARCHAR(64) NOT NULL DEFAULT 'default',
                    handler VARCHAR(255) NOT NULL,
                    payload TEXT NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    failed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    error TEXT
                )
            SQL);
    }

    /**
     * Indexes for the pop hot path (queue + availability scan).
     */
    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_queue_available ON jobs(queue, available_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_reserved_at ON jobs(reserved_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_failed_jobs_queue ON failed_jobs(queue)');
    }
}
