<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Blog\Domain\Entities\SearchResult;
use Modules\Blog\Infrastructure\Persistence\Statement;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * BlogSearchService — full-text search over storage/blog via an in-memory
 * SQLite FTS5 index.
 *
 * Articles are plain .md files, so the FTS index is derived from the
 * directory on every search request (same "no generated map" convention as
 * BlogArticlesService): the index lives in an in-memory SQLite connection,
 * is populated from the normalized article cards + the raw markdown body,
 * and results are ranked with bm25 (title/excerpt weigh more than body).
 *
 * Search features:
 * - **Pagination**: results are paginated with configurable per-page count
 * - **Highlighting**: matching terms are wrapped in <mark> tags via highlightText()
 * - **Fuzzy prefix matching**: each token is quoted as a prefix phrase ("token"*),
 *   so "php" matches "phpstorm", "PHP" etc. The unicode61 tokenizer folds
 *   diacritics, so "cisty" matches "čistý".
 *
 * Members-only articles (front matter members_only: true) ARE indexed — the
 * gated body is only ever used for MATCH ranking inside the per-request
 * in-memory index, never rendered: results come back as slugs and the index
 * page renders the standard cards (title + excerpt), same as the feeds. The
 * article page itself enforces the login gate.
 *
 * Query safety: the user input is split into word tokens and each token is
 * quoted as a prefix phrase ("token"*), so FTS5 operators (AND, OR, NOT,
 * quotes, parens) can never reach MATCH — a hostile query degrades to a
 * plain word search instead of syntax injection. Diacritics are folded by
 * the unicode61 tokenizer, so "cisty" and "čistý" match the same rows.
 */
final readonly class BlogSearchService
{
    private const int MAX_QUERY_LENGTH = 100;

    private const int MAX_TOKENS = 6;

    private const int DEFAULT_PER_PAGE = 9;

    public function __construct(
        private BlogArticlesService $articles,
        private AppPaths $paths,
    ) {
    }

    /**
     * Search all articles in the given locale, best match first. Returns a
     * paginated SearchResult with slugs, total count, and page metadata.
     *
     * The in-memory FTS index is built from the locale's own directory
     * (the default locale reads storage/blog, others storage/blog/{lang}),
     * so /blog?q= and /en/blog?q= search disjoint article sets.
     */
    public function search(string $query, string $locale, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): SearchResult
    {
        $tokens = $this->tokens($query);

        if ([] === $tokens) {
            return new SearchResult([], 0, 1, $perPage);
        }

        // The name is the search domain's, not a credential's: an FTS token is
        // a word the reader typed, split out of the query it arrived in.
        // @mago-expect lint:sensitive-parameter
        $match = implode(' ', array_map(static fn (string $token): string => '"' . $token . '"*', $tokens));

        $pdo = $this->index($locale);

        // Count total matching rows
        $countStmt = Statement::prepare($pdo,
            'SELECT COUNT(*) as cnt FROM blog_fts WHERE blog_fts MATCH :match',
        );
        $countStmt->execute([':match' => $match]);
        $total = (int) $countStmt->fetchColumn();

        // Paginated results
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $stmt = Statement::prepare($pdo,
            'SELECT slug FROM blog_fts WHERE blog_fts MATCH :match ORDER BY bm25(blog_fts, 10.0, 5.0, 3.0, 1.0) LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue(':match', $match, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $slugs = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $slugs[] = (string) $row['slug'];
        }

        return new SearchResult($slugs, $total, $page, $perPage);
    }

    /**
     * Highlight matching terms in text by wrapping them in <mark> tags.
     * Case-insensitive, whole-word matching. Safe for HTML output — the text
     * should already be escaped before calling this.
     *
     * For multi-word queries, each token is highlighted independently.
     */
    public static function highlightText(string $text, string $query): string
    {
        $tokens = self::extractHighlightTokens($query);

        if ([] === $tokens || '' === $text) {
            return $text;
        }

        // Build a single regex that matches any token (case-insensitive, UTF-8)
        $pattern = '/' . implode('|', array_map(preg_quote(...), $tokens)) . '/iu';

        return (string) preg_replace($pattern, '<mark>$0</mark>', $text);
    }

    /**
     * Extract highlight tokens from the query — same tokenization as the
     * search but without the limit, so all words are highlighted.
     *
     * @return list<string>
     */
    private static function extractHighlightTokens(string $query): array
    {
        $query = trim($query);

        if ('' === $query) {
            return [];
        }

        $tokens = [];
        foreach (preg_split('/[^\p{L}\p{N}_]+/u', $query) ?: [] as $part) {
            if ('' !== $part && mb_strlen($part) >= 2) {
                $tokens[] = $part;
            }
        }

        return $tokens;
    }

    /**
     * Build the in-memory FTS5 index from the article cards + raw bodies of
     * the given locale.
     */
    private function index(string $locale): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // Column order matters for bm25: weights are applied positionally,
        // so slug (UNINDEXED, never matched) sits last and title/excerpt/
        // tags/body get the 10.0/5.0/3.0/1.0 weights in the ORDER BY below.
        $pdo->exec('CREATE VIRTUAL TABLE blog_fts USING fts5(title, excerpt, tags, body, slug UNINDEXED)');

        $insert = Statement::prepare($pdo, 'INSERT INTO blog_fts (slug, title, excerpt, tags, body) VALUES (?, ?, ?, ?, ?)');
        $renderer = new MarkdownRenderer();

        foreach ($this->articles->all($locale) as $article) {
            $insert->execute([
                $article->slug,
                $article->title,
                $article->excerpt,
                implode(' ', $article->tags),
                $this->body($article->slug, $renderer, $locale),
            ]);
        }

        return $pdo;
    }

    /**
     * Raw markdown body of an article (front matter stripped) for indexing.
     */
    private function body(string $slug, MarkdownRenderer $renderer, string $locale): string
    {
        $file = $this->paths->storage(BlogArticlesService::directoryFor($locale) . DIRECTORY_SEPARATOR . $slug . '.md');
        $source = is_file($file) ? file_get_contents($file) : false;

        if (!is_string($source)) {
            return '';
        }

        return $renderer->extractFrontMatter($source)['body'];
    }

    /**
     * Split the raw query into safe word tokens (letters incl. diacritics,
     * digits, underscore). Everything else — FTS5 operators, quotes, parens,
     * punctuation — is stripped, so no query can inject MATCH syntax.
     *
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        $query = trim($query);

        if ('' === $query || mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return [];
        }

        $tokens = [];
        foreach (preg_split('/[^\p{L}\p{N}_]+/u', $query) ?: [] as $part) {
            if ('' !== $part) {
                $tokens[] = $part;
            }

            if (count($tokens) >= self::MAX_TOKENS) {
                break;
            }
        }

        return $tokens;
    }
}
