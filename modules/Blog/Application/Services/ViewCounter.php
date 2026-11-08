<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Blog\Infrastructure\Persistence\Statement;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;

/**
 * ViewCounter — tracks article page views in blog.db.
 *
 * Each article page view appends a row to the article_views table.
 * The count is returned by count() for display on article pages.
 *
 * The in-memory PDO statement cache means repeated calls to count()
 * for the same slug within a single request are free (no extra query).
 */
final class ViewCounter
{
    private static ?self $instance = null;

    private readonly \PDO $pdo;

    private function __construct()
    {
        $this->pdo = DatabaseConnectionManager::instance()->connection('blog');
        $this->ensureTable();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Reset the singleton (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Record a view for the given article slug.
     */
    public function record(string $slug): void
    {
        $stmt = Statement::prepare($this->pdo, 'INSERT INTO article_views (article_slug) VALUES (:slug)');
        $stmt->execute([':slug' => $slug]);
    }

    /**
     * Get the total view count for an article.
     */
    public function count(string $slug): int
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT COUNT(*) FROM article_views WHERE article_slug = :slug');
        $stmt->execute([':slug' => $slug]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get view counts for multiple articles at once.
     *
     * @param list<string> $slugs
     *
     * @return array<string, int> slug → count
     */
    public function counts(array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = Statement::prepare($this->pdo,
            "SELECT article_slug, COUNT(*) as cnt FROM article_views WHERE article_slug IN ({$placeholders}) GROUP BY article_slug",
        );
        $stmt->execute($slugs);

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['article_slug']] = (int) $row['cnt'];
        }

        // Ensure all slugs have an entry (even if 0)
        foreach ($slugs as $slug) {
            if (!isset($counts[$slug])) {
                $counts[$slug] = 0;
            }
        }

        return $counts;
    }

    /**
     * Format a view count for display (e.g., "1,234 views" or "No views yet").
     */
    public static function formatCount(int $count): string
    {
        if (0 === $count) {
            return '';
        }

        return number_format($count) . ' view' . (1 === $count ? '' : 's');
    }

    /**
     * Ensure the article_views table exists (idempotent).
     */
    private function ensureTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS article_views (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    article_slug VARCHAR(200) NOT NULL,
                    viewed_at   DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_article_views_slug ON article_views(article_slug)');
    }
}
