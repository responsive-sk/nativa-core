<?php

declare(strict_types = 1);

namespace Modules\Pagination\Pagination;

use Modules\Pagination\Contracts\Paginator;

/**
 * OffsetPaginator — numbered pages over a `LIMIT ? OFFSET ?` query.
 *
 * The caller counts the rows (`total`), asks the database for one page starting
 * at `offset()`, and wraps the result with `withItems()`. Two phases, because a
 * page number from a URL is not trustworthy: `?page=99999` on a three-page list
 * must land on page 3 and show rows, not an empty table — so `page()` clamps to
 * `[1, lastPage()]` and `offset()` is derived from the CLAMPED number. That is
 * why the caller reads `offset()` instead of computing it.
 *
 * `perPage` below 1 is clamped to 1 rather than rejected: a zero-page-size
 * query is a caller's arithmetic slip, and throwing would turn a bad link into
 * a 500 on a page whose job is to list things.
 *
 * `from()`/`to()` are the human numbers ("showing 21–40 of 137") and answer 0–0
 * for an empty collection — a range with no rows in it has no first row, which
 * is the one case a naive `offset + 1` gets wrong.
 */
final readonly class OffsetPaginator implements Paginator
{
    /**
     * @param list<mixed> $items
     * @param int         $page  the REQUESTED page; `page()` is the clamped answer
     */
    public function __construct(
        private array $items,
        private int $total,
        private int $perPage = 20,
        private int $page = 1,
    ) {
    }

    /**
     * The same page metadata with its rows attached.
     *
     * @param list<mixed> $items
     */
    public function withItems(array $items): self
    {
        return new self($items, $this->total, $this->perPage, $this->page);
    }

    /**
     * The page actually shown — the requested one, clamped to the range that
     * exists.
     */
    public function page(): int
    {
        return min(max(1, $this->page), $this->lastPage());
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage()));
    }

    /**
     * Where the query starts. Derived from the clamped page, so a page number
     * past the end reads the last page rather than past every row.
     */
    public function offset(): int
    {
        return ($this->page() - 1) * $this->perPage();
    }

    public function total(): int
    {
        return $this->total;
    }

    public function hasPrevious(): bool
    {
        return $this->page() > 1;
    }

    public function hasNext(): bool
    {
        return $this->page() < $this->lastPage();
    }

    /**
     * The number of the first row on this page, or 0 when the collection is
     * empty.
     */
    public function from(): int
    {
        return 0 === $this->total ? 0 : $this->offset() + 1;
    }

    /**
     * The number of the last row on this page, or 0 when the collection is
     * empty.
     */
    public function to(): int
    {
        return 0 === $this->total ? 0 : min($this->offset() + \count($this->items), $this->total);
    }

    /**
     * @return list<mixed>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @param \Closure(string): string $url page number → URL; the caller owns
     *                                       what the address looks like
     *
     * @return array{
     *     items: list<mixed>,
     *     meta: array<string, int|string|bool|null>,
     *     links: array<string, string|null>
     * }
     */
    public function toArray(\Closure $url): array
    {
        return [
            'items' => $this->items,
            'meta'  => [
                'total'        => $this->total,
                'per_page'     => $this->perPage(),
                'current_page' => $this->page(),
                'last_page'    => $this->lastPage(),
                'from'         => $this->from(),
                'to'           => $this->to(),
            ],
            'links' => [
                'previous' => $this->hasPrevious() ? $url((string) ($this->page() - 1)) : null,
                'next'     => $this->hasNext() ? $url((string) ($this->page() + 1)) : null,
            ],
        ];
    }

    /**
     * The page size actually used — 0 or a negative number would divide by zero
     * in `lastPage()`, so it is clamped once here and read everywhere else.
     */
    public function perPage(): int
    {
        return max(1, $this->perPage);
    }
}
