<?php

declare(strict_types = 1);

namespace Tests\Cache;

use Modules\Core\Application\Services\PageCacheService;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Tooling\ModuleCatalog;
use Modules\Tooling\ModuleInstaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PageCacheDirectoryTest — the page cache has ONE directory, whichever way a
 * consumer names it.
 *
 * `PageCacheService::DEFAULT_DIR` is base-relative (`storage/cache/pages`), and
 * three consumers compose it that way: `CacheInvalidator::flush()`,
 * `CardboardServices::pageCache()` and — after this file existed — the module
 * installer. `ModuleInstaller::flushPageCache()` used to hand the constant to
 * `AppPaths::storage()`, which prepends the storage root a SECOND time: the
 * flush then ran against `storage/storage/cache/pages` — a directory
 * `PageCacheService`'s own constructor creates, so the bug was invisible in
 * the tree (no files, and git does not track empty directories) and silent in
 * the result (`flushed: 0`, real cache untouched). Installing or removing a
 * module is exactly when stale HTML must go.
 *
 * The pair of assertions is deliberate: the first is the contract in one line
 * (the constant and the composer answer the same path), the second drives the
 * real code path and checks what only a filesystem can show — that the entry
 * disappeared from the cache an operator would look in, and that no nested
 * `storage/` was left behind for the next reader to wonder about.
 *
 * Kernel-level test, like the rest of `tests/`: nothing here names a module
 * outside the kernel-owned ones, so a tree with no modules installed runs it.
 */
final class PageCacheDirectoryTest extends TestCase
{
    /** Sentinel written into the real page cache, removed even on failure. */
    private string $sentinel = '';

    protected function tearDown(): void
    {
        if ('' !== $this->sentinel && is_file($this->sentinel)) {
            @unlink($this->sentinel);
        }

        parent::tearDown();
    }

    #[Test]
    public function theConstantAndTheStorageComposerNameOneDirectory(): void
    {
        $paths = AppPaths::instance();

        self::assertSame(
            $paths->storage('cache' . DIRECTORY_SEPARATOR . 'pages'),
            $paths->base() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, PageCacheService::DEFAULT_DIR),
            'PageCacheService::DEFAULT_DIR is base-relative; the storage composer must land on that same directory.'
            . ' A second `storage/` segment means one of the two is composed against the wrong root.',
        );
    }

    #[Test]
    public function flushingFromTheModuleInstallerEmptiesTheRealCache(): void
    {
        $paths = AppPaths::instance();
        $dir = $paths->storage('cache' . DIRECTORY_SEPARATOR . 'pages');
        $paths->ensureDir($dir);

        $this->sentinel = $dir . DIRECTORY_SEPARATOR . 'phpunit-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($this->sentinel, '{"probe":true}');

        $flushed = (new ModuleInstaller(ModuleCatalog::fromAppPaths()))->flushPageCache();

        self::assertGreaterThanOrEqual(
            1,
            $flushed,
            'The installer flushed 0 entries while an entry sat in the page cache the middleware writes to.',
        );

        self::assertFileDoesNotExist($this->sentinel, 'The flush left the entry it was supposed to delete.');

        self::assertDirectoryDoesNotExist(
            APP_BASE_PATH . '/storage/storage',
            'A nested `storage/storage` appeared: the page-cache path was composed against the storage root twice.'
            . ' `module:add`/`module:remove` must flush the cache an operator inspects, not a stray copy of it.',
        );
    }
}
