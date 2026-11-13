<?php

declare(strict_types = 1);

namespace Modules\PageCache\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\PageCache\Application\Services\PageCacheService;
use Modules\PageCache\Interfaces\HTTP\Middleware\PageCacheMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PageCacheInvalidationTest (Phase D) — a content write drops the pages it
 * changed, not the whole cache.
 *
 * Before this rule every article save called `CacheInvalidator::flush()`: the
 * blog was stale for nobody and the rest of the site paid a full re-render for
 * a change it never saw. The entry now records the request path it was
 * rendered for, and `invalidatePaths()` deletes exactly the entries under the
 * paths a write names.
 *
 * The rules pinned here are the ones a path list can get wrong:
 *
 *  - a path is a SEGMENT prefix — `/blog` covers `/blog/hello`, never
 *    `/blogfeed`;
 *  - `/` is the homepage, not every page;
 *  - every query variant of an invalidated page goes with it (they share a
 *    path and differ only in the key);
 *  - an entry with no recorded path (written before this metadata existed, or
 *    corrupted) is deleted rather than trusted: serving a stale article is the
 *    one outcome this cache must never produce;
 *  - unrelated pages survive, which is the entire point.
 *
 * The last test keeps the write path honest: a Blog action that goes back to
 * `CacheInvalidator::flush()` would pass every behavioural test here while
 * quietly re-rendering the site, so the call sites are read.
 */
final class PageCacheInvalidationTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        if (!class_exists(PageCacheService::class)) {
            $this->markTestSkipped('PageCache is not installed in this checkout.');
        }

        $this->cacheDir = sys_get_temp_dir() . '/nativa-invalidation-' . bin2hex(random_bytes(6));
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        if (PHP_SESSION_ACTIVE === session_status()) {
            session_destroy();
        }

        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->cacheDir);
    }

    #[Test]
    public function anArticleWriteKeepsEveryUnrelatedPageCached(): void
    {
        $home = Request::create('GET', '/');
        $index = Request::create('GET', '/blog');
        $article = Request::create('GET', '/blog/hello-world');
        $secondPage = Request::create('GET', '/blog', ['p' => '2']);
        $contact = Request::create('GET', '/kontakt');

        foreach ([$home, $index, $article, $secondPage, $contact] as $request) {
            $this->writeThroughMiddleware($request);
        }

        $deleted = $this->service()->invalidatePaths('/blog');

        $this->assertSame(
            3,
            $deleted,
            'The blog index, the article and the second index page share the `/blog` path prefix.',
        );
        $this->assertFalse($this->entryExists($index));
        $this->assertFalse($this->entryExists($article));
        $this->assertFalse($this->entryExists($secondPage), 'A query variant of an invalidated page is the same page.');
        $this->assertTrue(
            $this->entryExists($home),
            'The homepage is not under `/blog`; invalidating it there would make every write cost every page.',
        );
        $this->assertTrue($this->entryExists($contact), 'A page this write never touched must keep its render.');
    }

    #[Test]
    public function theRootPathInvalidatesOnlyTheHomepage(): void
    {
        $home = Request::create('GET', '/');
        $index = Request::create('GET', '/blog');
        $contact = Request::create('GET', '/kontakt');

        foreach ([$home, $index, $contact] as $request) {
            $this->writeThroughMiddleware($request);
        }

        $this->assertSame(1, $this->service()->invalidatePaths('/'));

        $this->assertFalse($this->entryExists($home));
        $this->assertTrue($this->entryExists($index));
        $this->assertTrue($this->entryExists($contact));
    }

    #[Test]
    public function aPrefixDoesNotReachAPathThatOnlyStartsWithTheSameCharacters(): void
    {
        $blog = Request::create('GET', '/blog');
        $blogFeed = Request::create('GET', '/blogfeed');

        foreach ([$blog, $blogFeed] as $request) {
            $this->writeThroughMiddleware($request);
        }

        $this->assertSame(1, $this->service()->invalidatePaths('/blog'));

        $this->assertFalse($this->entryExists($blog));
        $this->assertTrue(
            $this->entryExists($blogFeed),
            '`/blog` matched `/blogfeed` — a string prefix is not a path prefix.',
        );
    }

    /**
     * `Modules\Blog` is named inline behind `class_exists()` and never imported:
     * `tests/` sits inside PHPStan's scope while `src/Modules/Blog` is gitignored
     * operator state, so a `use` line here is an unconditional reference the
     * analyser reports as `class.notFound` on every kernel-only checkout — which
     * is exactly how CI run #1 on this file went red. Same idiom as
     * `AuthMiddlewareDenialTest`, and the guard has to sit in the same scope as
     * the call for PHPStan to narrow it.
     */
    #[Test]
    public function theBlogTagListCoversTheHomepageTheBlogAndAuthorProfiles(): void
    {
        if (!class_exists(\Modules\Blog\Application\Services\BlogPageCache::class)) {
            $this->markTestSkipped('The Blog slice is not installed in this kernel-only checkout.');
        }

        $this->assertSame(
            ['/', '/blog', '/authors'],
            \Modules\Blog\Application\Services\BlogPageCache::paths(),
            'An article write changes the homepage list, the blog pages and the author profile that lists the article.',
        );
    }

    #[Test]
    public function anEntryWithoutARecordedPathIsInvalidatedRatherThanTrusted(): void
    {
        $service = $this->service();
        $legacy = $service->cacheKeyForRequest(Request::create('GET', '/kontakt'));

        file_put_contents(
            $this->cacheDir . '/' . $legacy . '.json',
            json_encode([
                'content' => '<html>written before path metadata existed</html>',
                'statusCode' => 200,
                'headers' => ['Content-Type' => 'text/html; charset=UTF-8'],
                'cachedAt' => time(),
                'ttl' => 300,
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertTrue($this->entryExists(Request::create('GET', '/kontakt')), 'Precondition: the legacy entry exists.');

        $this->assertSame(
            1,
            $service->invalidatePaths('/blog'),
            'A path-less entry can be any page, so it cannot be proven unrelated.',
        );
        $this->assertFalse($this->entryExists(Request::create('GET', '/kontakt')));
    }

    #[Test]
    public function aCorruptEntryIsDeletedInsteadOfLeftToBeServed(): void
    {
        $this->writeThroughMiddleware(Request::create('GET', '/blog'));
        file_put_contents($this->cacheDir . '/corrupt.json', 'not json');

        $this->assertSame(2, $this->service()->invalidatePaths('/blog'));
        $this->assertFileDoesNotExist($this->cacheDir . '/corrupt.json');
    }

    #[Test]
    public function invalidatingNoPathsDeletesNothing(): void
    {
        $this->writeThroughMiddleware(Request::create('GET', '/blog'));

        $this->assertSame(0, $this->service()->invalidatePaths());
        $this->assertTrue($this->entryExists(Request::create('GET', '/blog')));
    }

    /**
     * The behavioural tests above would all still pass if a write called
     * `flush()` first: the blog would be fresh, and so would everything else.
     * What distinguishes the two rules is the call site, so the call sites are
     * read — every public write path invalidates, none flushes.
     */
    #[Test]
    public function everyBlogWritePathInvalidatesItsOwnPagesInsteadOfFlushingTheCache(): void
    {
        $root = APP_BASE_PATH;
        $callSites = [
            'modules/Blog/Interfaces/HTTP/Actions/ArticlesCreateAction.php',
            'modules/Blog/Interfaces/HTTP/Actions/ArticlesEditAction.php',
            'modules/Blog/Interfaces/HTTP/Actions/ArticlesDeleteAction.php',
            'modules/Blog/Interfaces/HTTP/Actions/ArticlePublishToggleAction.php',
            'modules/Blog/Interfaces/HTTP/Actions/BlogInlineEditAction.php',
            'modules/Blog/Interfaces/HTTP/Actions/TagsManageAction.php',
            'modules/Blog/Interfaces/HTTP/Actions/CategoriesManageAction.php',
        ];

        foreach ($callSites as $relativePath) {
            $source = (string) file_get_contents($root . '/' . $relativePath);

            $this->assertStringContainsString(
                'BlogPageCache::invalidate()',
                $source,
                $relativePath . ' no longer invalidates the blog pages it changed.',
            );
            $this->assertStringNotContainsString(
                'CacheInvalidator::flush()',
                $source,
                $relativePath . ' still flushes the whole cache after a content write.',
            );
        }
    }

    /**
     * Outbox cannot import `BlogPageCache` — Blog already imports Outbox for
     * cover jobs, and the catalogue closure graph refuses a cycle. So the
     * scheduled-publish handler spells the same three paths; this test is what
     * keeps the copy honest when Blog's list changes.
     */
    #[Test]
    public function theOutboxPublishHandlerInvalidatesTheSameArticlePagesAsTheBlogModule(): void
    {
        $root = APP_BASE_PATH;
        $source = (string) file_get_contents($root . '/modules/Outbox/Application/Services/ArticlePublishHandler.php');

        $this->assertStringContainsString(
            "CacheInvalidator::invalidate('/', '/blog', '/authors');",
            $source,
            'The outbox handler no longer invalidates the article pages, or its paths drifted from BlogPageCache::paths().',
        );
        $this->assertStringNotContainsString(
            'CacheInvalidator::flush()',
            $source,
            'A scheduled publish still flushes the whole cache.',
        );

        // Guarded like the call above it: the canonical Blog source is tracked,
        // so the file assertions hold on a kernel-only checkout where the slice
        // itself is not installed.
        if (!class_exists(\Modules\Blog\Application\Services\BlogPageCache::class)) {
            $this->markTestSkipped('The Blog slice is not installed in this kernel-only checkout.');
        }

        $this->assertSame(['/', '/blog', '/authors'], \Modules\Blog\Application\Services\BlogPageCache::paths());
    }

    private function service(): PageCacheService
    {
        return new PageCacheService($this->cacheDir);
    }

    private function writeThroughMiddleware(Request $request): void
    {
        $middleware = new PageCacheMiddleware($this->service());

        $middleware->handle($request, static fn (): Response => Response::html('<html>ok</html>'));
    }

    private function entryExists(Request $request): bool
    {
        return is_file($this->cacheDir . '/' . $this->service()->cacheKeyForRequest($request) . '.json');
    }
}
