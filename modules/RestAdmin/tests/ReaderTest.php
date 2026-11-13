<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\RestAdmin\Application\Mapping\ApiException;
use Modules\RestAdmin\Application\Mapping\FieldMapper;
use Modules\RestAdmin\Application\Mapping\ItemReader;
use Modules\RestAdmin\Application\Mapping\ListReader;
use Modules\RestAdmin\Application\Mapping\MappingException;
use Modules\RestAdmin\Domain\Model\AuthSpec;
use Modules\RestAdmin\Domain\Model\PaginationSpec;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceConfig;
use Modules\RestAdmin\Infrastructure\Http\FakeTransport;
use Modules\RestAdmin\Infrastructure\Http\HttpResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ReaderTest — the same reader code against three example services.
 *
 * This is the phase's acceptance criterion as a test: the blog fixture
 * (Laravel `data`+`meta`), the shop fixture (`results`+body `next`) and the
 * cursor fixture (`data`+`cursor.next`) are read by the SAME `ListReader`, with
 * only the resource's declared `pagination` differing. Nothing switches on the
 * service's name or URL.
 *
 * `FakeTransport` records the request, so the URL and the auth header are
 * asserted without a network: `?page=1&per_page=25` for page-shaped resources,
 * the raw cursor for cursor-shaped ones, `Bearer` only for the Authorization
 * header, and a percent-encoded id for a detail read.
 */
final class ReaderTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    private ?string $tokenBefore = null;

    private bool $tokenPrepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — its slice ships with the module.');
        }

        $before = getenv('RESTADMIN_BLOG_TOKEN');
        $this->tokenBefore = false === $before ? null : $before;
        putenv('RESTADMIN_BLOG_TOKEN=blog-secret');
        $this->tokenPrepared = true;
    }

    protected function tearDown(): void
    {
        if ($this->tokenPrepared) {
            putenv(null === $this->tokenBefore ? 'RESTADMIN_BLOG_TOKEN' : 'RESTADMIN_BLOG_TOKEN=' . $this->tokenBefore);
        }

        parent::tearDown();
    }

    #[Test]
    public function theBlogCollectionIsReadWithItsPageState(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, $this->fixture('blog-page-1.json')));

        $page = (new ListReader($transport))->read($this->blogService(), $this->resource());

        self::assertCount(2, $page->items);
        self::assertSame('Hello, Nativa', $page->items[0]['title']);
        self::assertSame(1, $page->state->currentPage);
        self::assertSame(3, $page->state->lastPage);
        self::assertSame(6, $page->state->total);
        self::assertTrue($page->state->hasNext);
    }

    #[Test]
    public function thePageRequestCarriesThePageAndThePageSize(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, $this->fixture('blog-page-1.json')));

        (new ListReader($transport))->read($this->blogService(), $this->resource(), page: 2);

        $request = $transport->lastRequest();

        self::assertSame('https://api.blog.test/v1/posts?page=2&per_page=25', $request->url);
        self::assertSame('Bearer blog-secret', $request->headers['authorization']);
    }

    #[Test]
    public function theShopCollectionWorksWithLinkStrategyAndNoHeader(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, $this->fixture('shop-page-1.json')));
        $service = new ServiceConfig(id: 'shop', name: 'Shop API', baseUrl: 'https://api.shop.test');

        $page = (new ListReader($transport))->read(
            $service,
            $this->resource(strategy: 'link', serviceId: 'shop', path: '/api/products'),
        );

        self::assertCount(2, $page->items);
        self::assertSame('Mug', $page->items[0]['name']);
        self::assertSame(5, $page->state->total);
        self::assertTrue($page->state->hasNext);
        self::assertSame('https://api.shop.test/api/products', $transport->lastRequest()->url);
    }

    #[Test]
    public function theCursorCollectionEchoesTheCursorItWasGiven(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, $this->fixture('oauth-page-1.json')));

        $page = (new ListReader($transport))->read(
            $this->blogService(),
            $this->resource(strategy: 'cursor', path: '/events'),
            cursor: 'cGFnZS0x',
        );

        self::assertSame('Y3Vyc29yLTI', $page->state->nextCursor);
        self::assertTrue($page->state->hasNext);
        self::assertSame('https://api.blog.test/v1/events?cursor=cGFnZS0x', $transport->lastRequest()->url);
    }

    #[Test]
    public function aSingleShotCollectionSaysThereIsNoNextPage(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, '{"data":[{"id":1},{"id":2}]}'));

        $page = (new ListReader($transport))->read($this->blogService(), $this->resource(strategy: 'none'));

        self::assertCount(2, $page->items);
        self::assertFalse($page->state->hasNext);
        self::assertSame(2, $page->state->total);
        self::assertSame('https://api.blog.test/v1/posts', $transport->lastRequest()->url);
    }

    #[Test]
    public function aValidationFailureCarriesFieldErrors(): void
    {
        $transport = new FakeTransport(new HttpResponse(422, $this->fixture('error-422-laravel.json')));

        try {
            (new ListReader($transport))->read($this->blogService(), $this->resource());
        } catch (ApiException $exception) {
            self::assertSame('validation', $exception->error->kind);
            self::assertTrue($exception->error->isValidation());
            self::assertSame(['The title field is required.'], $exception->error->fieldErrors['title']);

            return;
        }

        self::fail('A 422 was read as a page.');
    }

    #[Test]
    public function aTwoHundredThatIsNotJsonIsAMappingFailure(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, '<html>proxy error</html>'));

        $this->expectException(MappingException::class);

        (new ListReader($transport))->read($this->blogService(), $this->resource());
    }

    #[Test]
    public function theBlogItemIsReadByItsId(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, $this->fixture('blog-item.json')));

        $item = (new ItemReader($transport))->read($this->blogService(), $this->resource(), 1);

        self::assertSame('Hello, Nativa', $item['title']);
        self::assertSame('https://api.blog.test/v1/posts/1', $transport->lastRequest()->url);
    }

    /**
     * JSON:API keeps the readable fields under `attributes`, beside `id`.
     * The reader must not flatten them: the field mapper is what reads
     * through the wrapper, and `id` stays where the API put it.
     */
    #[Test]
    public function aJsonApiItemIsMappedThroughAttributes(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, $this->fixture('blog-item-jsonapi.json')));

        $item = (new ItemReader($transport))->read($this->blogService(), $this->resource(), 1);
        $mapper = new FieldMapper();

        self::assertSame('Hello, Nativa', $mapper->read($item, 'title'));
        self::assertTrue($mapper->has($item, 'published_at'));
        self::assertSame('1', $item['id']);
    }

    #[Test]
    public function aMissingItemIsReportedAsGoneRatherThanBroken(): void
    {
        $transport = new FakeTransport(new HttpResponse(404, '{"message":"Not found"}'));

        try {
            (new ItemReader($transport))->read($this->blogService(), $this->resource(), 9);
        } catch (ApiException $exception) {
            self::assertSame('not_found', $exception->error->kind);
            self::assertStringContainsString('no longer exists', $exception->error->message);

            return;
        }

        self::fail('A 404 was read as an item.');
    }

    #[Test]
    public function anIdIsEncodedAsOnePathSegment(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, $this->fixture('blog-item.json')));

        (new ItemReader($transport))->read($this->blogService(), $this->resource(), 'a/b');

        self::assertStringEndsWith('/posts/a%2Fb', $transport->lastRequest()->url);
    }

    private function blogService(): ServiceConfig
    {
        return new ServiceConfig(
            id: 'blog',
            name: 'Blog API',
            baseUrl: 'https://api.blog.test/v1',
            auth: new AuthSpec('Authorization', 'RESTADMIN_BLOG_TOKEN'),
        );
    }

    private function resource(
        string $strategy = 'page',
        string $serviceId = 'blog',
        string $path = '/posts',
        string $itemPath = '/posts/{id}',
    ): ResourceSpec {
        return new ResourceSpec(
            serviceId: $serviceId,
            name: 'posts',
            path: $path,
            itemPath: $itemPath,
            pagination: new PaginationSpec($strategy),
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }
}
