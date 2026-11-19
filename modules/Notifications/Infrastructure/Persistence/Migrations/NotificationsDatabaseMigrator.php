<?php

declare(strict_types = 1);

namespace Modules\Notifications\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Database\Infrastructure\Persistence\SqlDialect;

/**
 * Notifications Database Migrator — the per-user inbox schema in
 * `storage/data/notifications.db`.
 *
 * One row per (event, recipient): `notifiable_id` is the user the row belongs
 * to, and `read_at` is that user's own read state. Two moderators therefore
 * have two rows for one event, which is the whole point — a shared read flag on
 * the event would clear everyone's badge the moment one of them glanced at it.
 *
 * The module owns its database like every other slice (`users.db`, `outbox.db`,
 * …), so a deployment that never installs this module carries no table and no
 * file.
 */
#[AsMigrator(module: 'notifications', table: 'notifications')]
final readonly class NotificationsDatabaseMigrator implements MigratorInterface
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
        $output->writeln('<info>Migrating notifications.db...</info>');

        $this->createNotificationsTable();
        $this->widenNotifiableId();
        $this->createIndexes();

        $output->writeln('<info>  ✓ Created table: notifications</info>');
    }

    private function createNotificationsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS notifications (
                    id            VARCHAR(26) PRIMARY KEY,
                    notifiable_id VARCHAR(32) NOT NULL,
                    kind          VARCHAR(32) NOT NULL DEFAULT '',
                    title         VARCHAR(200) NOT NULL DEFAULT '',
                    body          TEXT NOT NULL DEFAULT '',
                    href          VARCHAR(200) NOT NULL DEFAULT '',
                    read_at       {$this->timestamp} DEFAULT NULL,
                    created_at    {$this->timestamp} DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    /**
     * A user id is 32 hex characters, not 26. SQLite accepted the longer value
     * because it ignores VARCHAR lengths; PostgreSQL refused the import. New
     * tables get 32 above, and this widens a table created by the old schema.
     */
    private function widenNotifiableId(): void
    {
        if ($this->dialect->isSqlite()) {
            return;
        }

        $statement = $this->pdo->prepare(
            'SELECT character_maximum_length FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
        );

        if (false === $statement) {
            return;
        }

        $statement->execute(['notifications', 'notifiable_id']);
        $length = $statement->fetchColumn();

        if (false === $length || (int) $length >= 32) {
            return;
        }

        $this->pdo->exec('ALTER TABLE notifications ALTER COLUMN notifiable_id TYPE VARCHAR(32)');
    }

    private function createIndexes(): void
    {
        // The inbox reads (newest first) and the badge counts (unread for one
        // user) are the only two queries this table serves, so they get one
        // index each — a third would be weight nobody reads.
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_notifications_inbox ON notifications(notifiable_id, created_at DESC)',
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_notifications_unread ON notifications(notifiable_id, read_at)',
        );
    }
}
