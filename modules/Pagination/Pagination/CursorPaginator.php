<?php

declare(strict_types = 1);

namespace Modules\Pagination\Pagination;

use Modules\Pagination\Contracts\Paginator;

/**
 * CursorPaginator — keyset pages, addressed by a value instead of a number.
 *
 * The caller fetches `limit + 1` rows (`WHERE key > ? ORDER BY key LIMIT ?`) and
 * hands them over with a closure that reads the cursor out of a row. One extra
 * row is how "is there more" is answered without a `COUNT(*)`: if it came back,
 * it is dropped from the page and only its cursor is kept. That is the whole
 * difference from `OffsetPaginator`, and the reason it exists — a row inserted
 * while a reader is paging shifts every `OFFSET` page under them, while a cursor
 * page cannot skip or repeat a row.
 *
 * `previousCursor()` is the cursor this page was REQUESTED with, not a value
 * derived from the rows: a keyset can always continue forward cheaply, and only
 * the caller knows the value it started at. Null therefore means "this is the
 * first page" and `toArray()` renders no previous link — correct rather than
 * missing, which is what a backwards page over a keyset would need the caller to
 * compute for itself anyway.
 *
 * A cursor is opaque here: it is a string on its way in and out, and its meaning
 * (an id, a timestamp, a composite key encoded by the caller) never enters this
 * class.
 */
final readonly class CursorPaginator implements Paginator
{
    /**
     * @param list<mixed> $items
     * @param ?string     $previousCursor the cursor this page started at (null = first page)
     * @param ?string     $nextCursor     the cursor of the next page (null = no more rows)
     */
    public function __construct(
        private array $items,
        private int $limit = 20,
        private ?string $previousCursor = null,
        private ?string $nextCursor = null,
    ) {
    }

    /**
     * Build the page from a fetch of `limit + 1` rows.
     *
     * @param list<mixed>                     $rows       at most limit + 1 rows, in cursor order
     * @param ?string                         $cursor     the cursor this page was requested with
     * @param \Closure(mixed): (string|int)   $cursorOf   the cursor value of one row
     */
    public static function fromRows(
        array $rows,
        int $limit,
        ?string $cursor,
        \Closure $cursorOf,
    ): self {
        $limit = max(1, $limit);
        $hasMore = \count($rows) > $limit;
        $kept = $hasMore ? array_slice($rows, 0, $limit) : $rows;

        $nextCursor = null;

        if ($hasMore && [] !== $kept) {
            $last = $kept[array_key_last($kept)];
            $nextCursor = (string) $cursorOf($last);
        }

        return new self($kept, $limit, $cursor, $nextCursor);
    }

    /**
     * @return list<mixed>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function limit(): int
    {
        return max(1, $this->limit);
    }

    public function hasMore(): bool
    {
        return null !== $this->nextCursor;
    }

    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    public function previousCursor(): ?string
    {
        return $this->previousCursor;
    }

    /**
     * @param \Closure(string): string $url cursor value → URL; the caller owns
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
                'per_page'        => $this->limit(),
                'has_more'        => $this->hasMore(),
                'next_cursor'     => $this->nextCursor,
                'previous_cursor' => $this->previousCursor,
            ],
            'links' => [
                'previous' => null !== $this->previousCursor ? $url($this->previousCursor) : null,
                'next'     => null !== $this->nextCursor ? $url($this->nextCursor) : null,
            ],
        ];
    }
}
