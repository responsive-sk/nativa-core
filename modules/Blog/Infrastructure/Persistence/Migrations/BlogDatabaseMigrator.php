<?php

declare(strict_types = 1);

namespace Modules\Blog\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;

/**
 * Blog Database Migrator — creates the blog.db schema.
 *
 * Table: article_views (one row per article page view). Used by
 * ViewCounter to track popularity. Every statement is idempotent
 * (CREATE TABLE IF NOT EXISTS), so re-running never fails.
 */
#[AsMigrator(module: 'blog', table: 'article_views')]
final readonly class BlogDatabaseMigrator implements MigratorInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating blog.db...</info>');

        $this->createArticleViewsTable();
        $this->createIndexes();

        $output->writeln('<info>  ✓ Created table: article_views</info>');
    }

    private function createArticleViewsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS article_views (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    article_slug VARCHAR(200) NOT NULL,
                    viewed_at   DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_article_views_slug ON article_views(article_slug)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_article_views_date ON article_views(viewed_at)');
    }
}
