<?php

declare(strict_types = 1);

namespace Modules\Blog\Domain\Entities;

/**
 * SearchResult — paginated blog search results with metadata.
 *
 * Returned by BlogSearchService::search() so the action and renderers
 * have a single typed object to work with instead of passing multiple
 * loose values.
 */
final readonly class SearchResult
{
    /**
     * @param list<string> $slugs   Matching article slugs, ranked by relevance
     * @param int          $total   Total number of matching articles
     * @param int          $page    Current page (1-based)
     * @param int          $perPage Results per page
     */
    public function __construct(
        public array $slugs,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages();
    }

    public function isEmpty(): bool
    {
        return [] === $this->slugs;
    }

    /**
     * Build the URL for a specific page, preserving the query and locale prefix.
     */
    public function pageUrl(int $page, string $basePath, string $query): string
    {
        $params = http_build_query(array_filter([
            'q' => '' !== $query ? $query : null,
            'page' => $page > 1 ? $page : null,
        ]));

        return $basePath . ('' !== $params ? '?' . $params : '');
    }
}
