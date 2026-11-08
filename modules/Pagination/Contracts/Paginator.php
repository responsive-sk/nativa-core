<?php

declare(strict_types = 1);

namespace Modules\Pagination\Contracts;

/**
 * Paginator — one page of rows plus the links that move between pages.
 *
 * Deliberately transport-free: a paginator never asks a Router, a Request or a
 * database for anything. `toArray()` takes a CLOSURE that turns one page value
 * into a URL, so the caller decides how a page is ADDRESSED — the admin panel
 * builds `?page=2`, an API builds `/articles?cursor=abc` — while the paginator
 * owns only the arithmetic and the meta every client needs. That is why the
 * closure takes a value and not a `(parameter name, value)` pair: the name of
 * the query key is the surface's word, and a class that spells it would be
 * renaming another module's URL the moment the panel renamed its own.
 *
 * Why this is a module and not kernel code: `tests/Architecture/KernelShapeTest`
 * requires every concrete class in `Modules\Core` to be approved in writing and
 * caps that list at 65 entries (`MAX_APPROVED`), because the kernel is supposed
 * to stay contracts. A shared utility that the panel and a future API both use
 * is a catalogue module — the same answer `modules/Validation/` already gives
 * for shared rules.
 *
 * Two implementations, two shapes of the same problem:
 *
 * - `OffsetPaginator` — page numbers over a `LIMIT/OFFSET` query. Simple, stable
 *   ordering required, and it can jump to any page.
 * - `CursorPaginator` — keyset paging over a `WHERE key > ?` query. No page
 *   numbers, no drift when rows are inserted, and no `OFFSET` cost on a deep
 *   page.
 *
 * @see \Modules\Pagination\Pagination\OffsetPaginator
 * @see \Modules\Pagination\Pagination\CursorPaginator
 */
interface Paginator
{
    /**
     * The rows on this page.
     *
     * @return list<mixed>
     */
    public function items(): array;

    /**
     * This page as one array: `items`, `meta` and `links`.
     *
     * @param \Closure(string): string $url target value (page number or cursor) →
     *                                       URL; the caller decides what a page
     *                                       looks like in its own surface
     *
     * @return array{
     *     items: list<mixed>,
     *     meta: array<string, int|string|bool|null>,
     *     links: array<string, string|null>
     * }
     */
    public function toArray(\Closure $url): array;
}
