<?php

declare(strict_types = 1);

namespace Modules\Blog\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Database\Infrastructure\Persistence\SqlDialect;

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
    private SqlDialect $dialect;

    private string $autoIncrement;

    private string $timestamp;

    public function __construct(private \PDO $pdo)
    {
        $this->dialect = SqlDialect::forPdo($pdo);
        $this->autoIncrement = $this->dialect->autoIncrementColumn();
        $this->timestamp = $this->dialect->timestampType();
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
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS article_views (
                    {$this->autoIncrement},
                    article_slug VARCHAR(200) NOT NULL,
                    viewed_at   {$this->timestamp} DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_article_views_slug ON article_views(article_slug)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_article_views_date ON article_views(viewed_at)');
    }
}
