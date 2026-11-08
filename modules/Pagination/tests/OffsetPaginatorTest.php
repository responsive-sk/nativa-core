<?php

declare(strict_types = 1);

namespace Modules\Pagination\Tests;

use Modules\Pagination\Pagination\OffsetPaginator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * OffsetPaginatorTest — the arithmetic a list page shows, and the two places it
 * is tempting to get wrong.
 *
 * 1. **A page number from a URL is not a range.** `?page=99` on a three-page
 *    list must land on page 3 with rows, not on an empty table with a broken
 *    pager — which is why `offset()` is read off the clamped page and asserted
 *    here rather than trusted from the request.
 * 2. **An empty collection has no first row.** `from()`/`to()` answer 0–0, not
 *    1–0, because "showing 1–0 of 0" is what makes an operator think the query
 *    broke.
 *
 * Links are asserted through a closure that spells the URL the way the admin
 * panel does, which is also the proof that the paginator never reaches for a
 * router itself.
 */
final class OffsetPaginatorTest extends TestCase
{
    /**
     * `modules/` is the committed catalogue, not the installed slice: a fresh
     * clone has no `src/Modules/Pagination`, so the class this file names is
     * not autoloadable there. Every other module test skips in that shape, and
     * the `Modules` CI job installs the catalogue and runs this file for real.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Pagination')) {
            $this->markTestSkipped('Pagination is not installed.');
        }
    }

    #[Test]
    public function theFirstPageNamesItsRowsAndLinksForward(): void
    {
        $page = new OffsetPaginator($this->rows(20), total: 137, perPage: 20, page: 1);

        self::assertSame(1, $page->page());
        self::assertSame(7, $page->lastPage());
        self::assertSame(0, $page->offset());
        self::assertSame(1, $page->from());
        self::assertSame(20, $page->to());
        self::assertFalse($page->hasPrevious());
        self::assertTrue($page->hasNext());

        $array = $page->toArray($this->url());

        self::assertSame([
            'total'        => 137,
            'per_page'     => 20,
            'current_page' => 1,
            'last_page'    => 7,
            'from'         => 1,
            'to'           => 20,
        ], $array['meta']);
        self::assertNull($array['links']['previous'], 'the first page has nowhere to go back to');
        self::assertSame('/notifications?page=2', $array['links']['next']);
    }

    #[Test]
    public function theLastPageEndsAtTheTotalEvenWhenItIsNotFull(): void
    {
        $page = new OffsetPaginator($this->rows(17), total: 137, perPage: 20, page: 7);

        self::assertSame(7, $page->page());
        self::assertSame(120, $page->offset());
        self::assertSame(121, $page->from());
        self::assertSame(137, $page->to(), 'the last page reports the total, not the page size');
        self::assertTrue($page->hasPrevious());
        self::assertFalse($page->hasNext());
        self::assertNull($page->toArray($this->url())['links']['next']);
    }

    /**
     * A hand-edited `?page=` is the normal way this breaks: the pager is right,
     * the query is right, and the page is empty because the offset went past
     * every row.
     */
    #[Test]
    public function aPageBeyondTheEndReadsTheLastPage(): void
    {
        $page = new OffsetPaginator([], total: 137, perPage: 20, page: 99);

        self::assertSame(7, $page->page());
        self::assertSame(120, $page->offset(), 'the offset follows the clamped page, never the requested one');
        self::assertSame(121, $page->from());
        self::assertFalse($page->hasNext());
        self::assertTrue($page->hasPrevious());
    }

    #[Test]
    public function aPageNumberBelowOneIsTheFirstPage(): void
    {
        foreach ([0, -5] as $requested) {
            $page = new OffsetPaginator($this->rows(20), total: 137, perPage: 20, page: $requested);

            self::assertSame(1, $page->page(), 'page ' . $requested . ' is not a page');
            self::assertSame(0, $page->offset());
        }
    }

    #[Test]
    public function anEmptyCollectionHasNoRange(): void
    {
        $page = new OffsetPaginator([], total: 0, perPage: 20, page: 1);

        self::assertSame(1, $page->lastPage());
        self::assertSame(0, $page->from());
        self::assertSame(0, $page->to());
        self::assertFalse($page->hasPrevious());
        self::assertFalse($page->hasNext());

        $array = $page->toArray($this->url());

        self::assertSame([], $array['items']);
        self::assertNull($array['links']['previous']);
        self::assertNull($array['links']['next']);
    }

    /**
     * A zero page size would divide by zero in `lastPage()`. Clamped rather
     * than thrown: this arrives from a caller's arithmetic, not from a user, and
     * a listing page that 500s over its own page size is worse than one that
     * shows single rows.
     */
    #[Test]
    public function aPageSizeBelowOneIsOne(): void
    {
        $page = new OffsetPaginator(['row-2'], total: 3, perPage: 0, page: 2);

        self::assertSame(3, $page->lastPage());
        self::assertSame(2, $page->page());
        self::assertSame(2, $page->from());
        self::assertSame(2, $page->to());
    }

    #[Test]
    public function attachingRowsKeepsTheMetadata(): void
    {
        $empty = new OffsetPaginator([], total: 45, perPage: 20, page: 3);
        $filled = $empty->withItems($this->rows(5));

        self::assertSame(3, $filled->page());
        self::assertSame(45, $filled->total());
        self::assertSame(41, $filled->from());
        self::assertSame(45, $filled->to());
        self::assertSame(5, \count($filled->items()));
        self::assertSame([], $empty->items(), 'the original page is untouched — the value object is immutable');
    }

    /**
     * @return list<array{id: int}>
     */
    private function rows(int $count): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['id' => $i];
        }

        return $rows;
    }

    /**
     * The panel's URL shape — page 1 is the bare list, every other page adds the
     * parameter. The key is spelled HERE, in the caller, because that is who owns
     * the address; the paginator only hands over the number.
     *
     * @return \Closure(string): string
     */
    private function url(): \Closure
    {
        return static fn (string $page): string => '1' === $page
            ? '/notifications'
            : '/notifications?page=' . $page;
    }
}
