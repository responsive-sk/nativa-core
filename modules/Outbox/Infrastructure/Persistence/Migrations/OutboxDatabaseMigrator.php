<?php

declare(strict_types = 1);

namespace Modules\Outbox\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;

/**
 * Outbox Database Migrator — creates the outbox.db schema.
 *
 * The outbox pattern ensures atomicity between business operations and
 * domain event publishing. Events are stored in the outbox table within
 * the same transaction as the business operation, then processed async.
 */
#[AsMigrator(module: 'outbox', table: 'outbox_messages')]
final readonly class OutboxDatabaseMigrator implements MigratorInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating outbox.db...</info>');

        $this->createOutboxMessagesTable();
        $this->createIndexes();

        $output->writeln('<info>  ✓ Created table: outbox_messages</info>');
    }

    private function createOutboxMessagesTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS outbox_messages (
                    id              VARCHAR(32) PRIMARY KEY,
                    event_type      VARCHAR(255) NOT NULL,
                    event_payload   TEXT NOT NULL,
                    metadata        TEXT DEFAULT NULL,
                    status          VARCHAR(20) NOT NULL DEFAULT 'pending',
                    attempts        INTEGER NOT NULL DEFAULT 0,
                    max_attempts    INTEGER NOT NULL DEFAULT 5,
                    last_attempt_at DATETIME DEFAULT NULL,
                    processed_at    DATETIME DEFAULT NULL,
                    error_message   TEXT DEFAULT NULL,
                    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_outbox_status ON outbox_messages(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_outbox_created ON outbox_messages(created_at ASC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_outbox_event_type ON outbox_messages(event_type)');
    }
}
