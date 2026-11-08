<?php

declare(strict_types = 1);

namespace Modules\Comments\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;

/**
 * Comments Database Migrator — creates the comments.db schema.
 *
 * Table: comments (one row per comment on an article slug). Status lifecycle
 * pending → approved | rejected; only approved rows are rendered publicly.
 * Every statement is idempotent (CREATE TABLE IF NOT EXISTS), so re-running
 * never fails or duplicates.
 */
#[AsMigrator(module: 'comments', table: 'comments')]
final readonly class CommentsDatabaseMigrator implements MigratorInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating comments.db...</info>');

        $this->createCommentsTable();
        $this->createIndexes();

        $output->writeln('<info>  ✓ Created table: comments</info>');
    }

    private function createCommentsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS comments (
                    id           VARCHAR(32) PRIMARY KEY,
                    article_slug VARCHAR(200) NOT NULL,
                    user_id      VARCHAR(64) DEFAULT NULL,
                    guest_name   VARCHAR(120) DEFAULT NULL,
                    guest_email  VARCHAR(255) DEFAULT NULL,
                    body         TEXT NOT NULL,
                    status       VARCHAR(12) NOT NULL DEFAULT 'pending',
                    ip_address   VARCHAR(45) DEFAULT NULL,
                    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_article ON comments(article_slug, status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_status ON comments(status)');
    }
}
