<?php

declare(strict_types = 1);

namespace Modules\Audit\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;

/**
 * Audit Database Migrator — creates the audit.db schema.
 *
 * Table: audit_log (one row per admin write action). Append-only by design:
 * the repository exposes no update/delete, and two SQLite triggers abort any
 * UPDATE/DELETE at the database layer — the log can never be tampered with.
 * Every statement is idempotent (CREATE TABLE/TRIGGER IF NOT EXISTS).
 */
#[AsMigrator(module: 'audit', table: 'audit_log')]
final readonly class AuditDatabaseMigrator implements MigratorInterface
{
    public function __construct(private \PDO $pdo)
    {
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
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS audit_log (
                    id            VARCHAR(32) PRIMARY KEY,
                    actor_user_id VARCHAR(64) NOT NULL,
                    actor_name    VARCHAR(120) NOT NULL,
                    action        VARCHAR(80) NOT NULL,
                    target        VARCHAR(255) NOT NULL DEFAULT '',
                    detail        TEXT NOT NULL DEFAULT '',
                    ip_address    VARCHAR(45) DEFAULT NULL,
                    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
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
     * aborted by SQLite before it can take effect.
     */
    private function createImmutabilityTriggers(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS audit_log_no_update
                BEFORE UPDATE ON audit_log
                BEGIN
                    SELECT RAISE(ABORT, 'audit_log is immutable');
                END
            SQL);

        $this->pdo->exec(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS audit_log_no_delete
                BEFORE DELETE ON audit_log
                BEGIN
                    SELECT RAISE(ABORT, 'audit_log is immutable');
                END
            SQL);
    }
}
