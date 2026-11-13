<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\RestAdmin\Application\Mapping\Pagination\CursorStrategy;
use Modules\RestAdmin\Application\Mapping\Pagination\LinkHeaderStrategy;
use Modules\RestAdmin\Application\Mapping\Pagination\PageStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PaginationStrategyTest — one answer per strategy, asserted field by field.
 *
 * The point of the phase is that the same resource code reads a Laravel
 * paginator, a Link-header API and a cursor feed; the strategies are where the
 * differences live, so they are tested against the example bodies rather than
 * against hand-written minimal arrays where possible.
 *
 * Two shapes are deliberately covered for `page`: Laravel's `meta`/`links`
 * wrapper and the blog example's flat `current_page`/`next_page_url` answer,
 * because both are the same paginator with a different serialiser.
 */
final class PaginationStrategyTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — its slice ships with the module.');
        }
    }

    #[Test]
    public function aLaravelMetaBlockDescribesThePage(): void
    {
        $state = (new PageStrategy())->state($this->payload('blog-page-1.json'), []);

        self::assertSame(1, $state->currentPage);
        self::assertSame(3, $state->lastPage);
        self::assertSame(2, $state->perPage);
        self::assertSame(6, $state->total);
        self::assertTrue($state->hasNext);
        self::assertFalse($state->hasPrevious);
        self::assertSame('https://api.blog.test/v1/posts?page=2', $state->nextUrl);
    }

    #[Test]
    public function aFlatLaravelPaginatorWorksWithoutMeta(): void
    {
        $state = (new PageStrategy())->state([
            'data'           => [],
            'current_page'   => 2,
            'last_page'      => 4,
            'per_page'       => 10,
            'total'          => 31,
            'next_page_url'  => 'https://api.blog.test/v1/authors?page=3',
            'prev_page_url'  => 'https://api.blog.test/v1/authors?page=1',
        ], []);

        self::assertSame(2, $state->currentPage);
        self::assertSame(4, $state->lastPage);
        self::assertSame(31, $state->total);
        self::assertTrue($state->hasNext);
        self::assertTrue($state->hasPrevious);
    }

    #[Test]
    public function aLinkHeaderCarriesNavigationAndTheTotal(): void
    {
        $state = (new LinkHeaderStrategy())->state([], [
            'link'          => '<https://api.test/items?page=2>; rel="next", <https://api.test/items?page=4>; rel="last"',
            'x-total-count' => '31',
        ]);

        self::assertSame(1, $state->currentPage);
        self::assertSame(4, $state->lastPage);
        self::assertSame(31, $state->total);
        self::assertSame('https://api.test/items?page=2', $state->nextUrl);
        self::assertTrue($state->hasNext);
        self::assertFalse($state->hasPrevious);
    }

    /**
     * The shop example puts the same navigation in the body. Declaring `link`
     * for it must work without inventing a header the service never sends.
     */
    #[Test]
    public function theShopBodyNavigationIsTheLinkFallback(): void
    {
        $state = (new LinkHeaderStrategy())->state($this->payload('shop-page-1.json'), []);

        self::assertSame(1, $state->currentPage);
        self::assertSame(5, $state->total);
        self::assertSame('/api/products?page=2', $state->nextUrl);
        self::assertTrue($state->hasNext);
        self::assertFalse($state->hasPrevious);
    }

    #[Test]
    public function aCursorIsTheNextTokenAndTheEndOfTheFeed(): void
    {
        $state = (new CursorStrategy())->state($this->payload('oauth-page-1.json'), []);

        self::assertSame('Y3Vyc29yLTI', $state->nextCursor);
        self::assertTrue($state->hasNext);
        self::assertNull($state->total);
        self::assertSame(1, $state->currentPage);
    }

    #[Test]
    public function anEmptyCursorMeansThereIsNoNextPage(): void
    {
        $state = (new CursorStrategy())->state(['data' => [], 'cursor' => ['next' => null]], []);

        self::assertNull($state->nextCursor);
        self::assertFalse($state->hasNext);
    }

    #[Test]
    public function theFlatterNextCursorSpellingIsReadToo(): void
    {
        $state = (new CursorStrategy())->state(['data' => [], 'next_cursor' => 'abc'], []);

        self::assertSame('abc', $state->nextCursor);
        self::assertTrue($state->hasNext);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $name): array
    {
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/' . $name),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        return $decoded;
    }
}
