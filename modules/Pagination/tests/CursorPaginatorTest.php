<?php

declare(strict_types = 1);

namespace Modules\Pagination\Tests;

use Modules\Pagination\Pagination\CursorPaginator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CursorPaginatorTest — one extra row is the whole contract.
 *
 * A keyset page is fetched as `limit + 1` rows and the caller never asks for a
 * count: if the extra row came back there is a next page, and if it did not
 * there is not. Both halves are asserted, because the failure is silent in
 * opposite directions — a paginator that ignores the extra row stops paging
 * early, and one that renders it duplicates a row on the next page.
 *
 * The cursor is opaque here on purpose (an id in this test), which is what lets
 * the same class serve an id keyset, a timestamp keyset and a composite key the
 * caller encodes itself.
 */
final class CursorPaginatorTest extends TestCase
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
    public function aFetchWithoutTheExtraRowIsTheLastPage(): void
    {
        $page = CursorPaginator::fromRows(
            [['id' => 1], ['id' => 2], ['id' => 3]],
            limit: 3,
            cursor: null,
            cursorOf: static fn (array $row): string => (string) $row['id'],
        );

        self::assertCount(3, $page->items());
        self::assertFalse($page->hasMore(), 'no extra row means no next page');
        self::assertNull($page->nextCursor());
        self::assertNull($page->previousCursor(), 'the first page started nowhere');
    }

    #[Test]
    public function theExtraRowBecomesTheNextCursorWithoutAppearing(): void
    {
        $page = CursorPaginator::fromRows(
            [['id' => 11], ['id' => 12], ['id' => 13], ['id' => 14]],
            limit: 3,
            cursor: '10',
            cursorOf: static fn (array $row): string => (string) $row['id'],
        );

        self::assertSame([11, 12, 13], array_column($page->items(), 'id'), 'the page holds exactly limit rows');
        self::assertTrue($page->hasMore());
        self::assertSame('13', $page->nextCursor(), 'the next page starts at the last row KEPT');
        self::assertSame('10', $page->previousCursor());

        $array = $page->toArray($this->url());

        self::assertSame([
            'per_page'        => 3,
            'has_more'        => true,
            'next_cursor'     => '13',
            'previous_cursor' => '10',
        ], $array['meta']);
        self::assertSame('/feed?cursor=13', $array['links']['next']);
        self::assertSame('/feed?cursor=10', $array['links']['previous']);
    }

    /**
     * A caller that fetches limit rows and stops cannot page forward, and this
     * is the shape that documents why: the paginator cannot invent a cursor for
     * a row it was never given.
     */
    #[Test]
    public function aFullPageWithoutTheExtraRowIsStillTheLastPage(): void
    {
        $page = CursorPaginator::fromRows(
            [['id' => 1], ['id' => 2]],
            limit: 2,
            cursor: null,
            cursorOf: static fn (array $row): string => (string) $row['id'],
        );

        self::assertCount(2, $page->items());
        self::assertFalse($page->hasMore());
        self::assertNull($page->toArray($this->url())['links']['next']);
    }

    #[Test]
    public function anEmptyPageIsAlsoTheLastPage(): void
    {
        $page = CursorPaginator::fromRows([], limit: 20, cursor: '99', cursorOf: static fn (array $row): string => '');

        self::assertSame([], $page->items());
        self::assertFalse($page->hasMore());
        self::assertNull($page->nextCursor());
        self::assertSame('99', $page->previousCursor(), 'a cursor page that came back empty can still go back');
    }

    #[Test]
    public function aLimitBelowOneIsOne(): void
    {
        $page = CursorPaginator::fromRows(
            [['id' => 1], ['id' => 2]],
            limit: 0,
            cursor: null,
            cursorOf: static fn (array $row): string => (string) $row['id'],
        );

        self::assertCount(1, $page->items());
        self::assertSame(1, $page->limit());
        self::assertTrue($page->hasMore());
        self::assertSame('1', $page->nextCursor());
    }

    /**
     * @return \Closure(string): string
     */
    private function url(): \Closure
    {
        return static fn (string $cursor): string => '/feed?cursor=' . $cursor;
    }
}
