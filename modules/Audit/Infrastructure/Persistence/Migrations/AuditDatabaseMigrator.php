<?php

declare(strict_types = 1);

namespace Modules\Audit\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Database\Infrastructure\Persistence\SqlDialect;

/**
 * Audit Database Migrator — creates the audit.db schema.
 *
 * Table: audit_log (one row per admin write action). Append-only by design:
 * the repository exposes no update/delete, and two triggers abort any
 * UPDATE/DELETE at the database layer — a RAISE in SQLite, a plpgsql function
 * in PostgreSQL — so the log can never be tampered with.
 * Every statement is idempotent (CREATE TABLE/TRIGGER IF NOT EXISTS).
 */
#[AsMigrator(module: 'audit', table: 'audit_log')]
final readonly class AuditDatabaseMigrator implements MigratorInterface
{
    private SqlDialect $dialect;

    private string $timestamp;

    public function __construct(private \PDO $pdo)
    {
        $this->dialect = SqlDialect::forPdo($pdo);
        $this->timestamp = $this->dialect->timestampType();
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating audit.db...</info>');

        $this->createAuditLogTable();
        $this->createIndexes();
        $this->createImmutabilityTriggers();

        $output->writeln('<info>  ✓ Created table: audit_log (append-only + immutable triggers)</info>');
    }

    private function createAuditLogTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS audit_log (
                    id            VARCHAR(32) PRIMARY KEY,
                    actor_user_id VARCHAR(64) NOT NULL,
                    actor_name    VARCHAR(120) NOT NULL,
                    action        VARCHAR(80) NOT NULL,
                    target        VARCHAR(255) NOT NULL DEFAULT '',
                    detail        TEXT NOT NULL DEFAULT '',
                    ip_address    VARCHAR(45) DEFAULT NULL,
                    created_at    {$this->timestamp} DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at DESC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_log(action)');
    }

    /**
     * Immutability enforcement — any attempt to modify or delete a row is
     * refused by the database before it can take effect. SQLite aborts in the
     * trigger body; PostgreSQL raises from a function the triggers call.
     */
    private function createImmutabilityTriggers(): void
    {
        foreach ($this->dialect->immutableTable('audit_log', 'audit_log is immutable') as $statement) {
            $this->pdo->exec($statement);
        }
    }
}
