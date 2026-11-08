<?php

declare(strict_types = 1);

namespace Modules\Notifications\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;

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
    public function __construct(private \PDO $pdo)
    {
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating notifications.db...</info>');

        $this->createNotificationsTable();
        $this->createIndexes();

        $output->writeln('<info>  ✓ Created table: notifications</info>');
    }

    private function createNotificationsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS notifications (
                    id            VARCHAR(26) PRIMARY KEY,
                    notifiable_id VARCHAR(26) NOT NULL,
                    kind          VARCHAR(32) NOT NULL DEFAULT '',
                    title         VARCHAR(200) NOT NULL DEFAULT '',
                    body          TEXT NOT NULL DEFAULT '',
                    href          VARCHAR(200) NOT NULL DEFAULT '',
                    read_at       DATETIME DEFAULT NULL,
                    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
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
