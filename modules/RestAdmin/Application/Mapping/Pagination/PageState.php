<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping\Pagination;

/**
 * PageState — what one answer says about where the reader is.
 *
 * Deliberately strategy-neutral: a page number and a last page, or a next
 * cursor, or just "there is a next URL". The panel renders the same pager for
 * all three, and the MCP `fetch_items` tool hands the same fields back, so the
 * vocabulary lives here rather than in each strategy.
 *
 * `null` means "the service did not say", which is different from zero: a
 * cursor API has no total, and a link-header API may have no last page. The
 * pager hides what it cannot know instead of inventing a number.
 */
final readonly class PageState
{
    public function __construct(
        public int $currentPage = 1,
        public ?int $lastPage = null,
        public ?int $perPage = null,
        public ?int $total = null,
        public ?string $nextCursor = null,
        public bool $hasNext = false,
        public bool $hasPrevious = false,
        public ?string $nextUrl = null,
        public ?string $previousUrl = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'current_page'  => $this->currentPage,
            'last_page'     => $this->lastPage,
            'per_page'      => $this->perPage,
            'total'         => $this->total,
            'next_cursor'   => $this->nextCursor,
            'has_next'      => $this->hasNext,
            'has_previous'  => $this->hasPrevious,
            'next_url'      => $this->nextUrl,
            'previous_url'  => $this->previousUrl,
        ];
    }
}
