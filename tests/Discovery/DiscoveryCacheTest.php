<?php

declare(strict_types = 1);

namespace Tests\Discovery;

use Modules\Core\Application\Services\DiscoveryCache;
use Modules\Core\Interfaces\HTTP\Actions\HomepageAction;
use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareRegistry;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Tooling\ModuleInstaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * DiscoveryCacheTest (RFC-1) — the fingerprint-validated discovery cache
 * (route entries + middleware classes):
 *
 * - a cold build stores exactly what the direct scans find;
 * - a warm build returns the identical payload WITHOUT running any scanner;
 * - a corrupt / version-mismatched / stale file degrades to a rescan
 *   (never a 500, never stale entries);
 * - `module:add` invalidates the cache like it invalidates the migration
 *   marker (same module set, same fate).
 *
 * The real tree is the scan fixture (no synthetic actions — `Router` maps
 * files under the action dirs to `Modules\…` classes, so fixtures outside
 * `src/Modules/` would never resolve). Temp files live in the OS temp dir,
 * except the invalidation leg, which asserts against the real cache path.
 */
final class DiscoveryCacheTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach ($this->tmpDirs as $dir) {
            if (is_dir($dir)) {
                ModuleInstaller::deleteTree($dir);
            }
        }

        $this->tmpFiles = [];
        $this->tmpDirs = [];

        MiddlewareRegistry::reset();

        parent::tearDown();
    }

    #[Test]
    public function coldBuildMatchesDirectScanAndWarmReturnsIdenticalWithoutScanning(): void
    {
        $dirs = [APP_BASE_PATH . '/src/Modules'];
        $cache = new DiscoveryCache($dirs, $this->tmpFile());

        $scans = 0;
        $scanRoutes = static function () use ($dirs, &$scans): array {
            $scans++;

            return (new Router($dirs))->toEntries();
        };
        $scanMiddleware = static function () use (&$scans): array {
            $scans++;

            return MiddlewareRegistry::discoveredClasses();
        };
        $cold = $cache->routes($scanRoutes, $scanMiddleware);

        $this->assertSame(2, $scans, 'Cold build must run both scanners exactly once.');
        $this->assertGreaterThan(0, $cold);

        // A second, independent scan finds the same SET (order may differ —
        // the Router iterates in readdir order, so compare canonicalized).
        $direct = (new Router($dirs))->toEntries();
        $this->assertSame(
            $this->canonicalize($direct),
            $this->canonicalize($cold),
            'Cached entries must match a direct Router scan.',
        );

        // Warm: neither scanner may run, and the entries must be identical
        // down to the order (scan order is part of match precedence).
        $warm = $cache->routes(
            static function (): array {
                throw new RuntimeException('Warm build must not run the scanner.');
            },
            static function (): array {
                throw new RuntimeException('Warm build must not run the scanner.');
            },
        );

        $this->assertSame($cold, $warm);
        $this->assertSame(2, $scans);

        // The middleware section rode along in the same payload.
        $this->assertSame(MiddlewareRegistry::discoveredClasses(), $cache->middleware($scanRoutes, $scanMiddleware));
    }

    #[Test]
    public function corruptCacheFileFallsBackToScan(): void
    {
        $dirs = [APP_BASE_PATH . '/src/Modules'];
        $file = $this->tmpFile();
        file_put_contents($file, "<?php\nreturn 'not-an-array';\n");

        $cache = new DiscoveryCache($dirs, $file);
        $scans = 0;
        $entries = $cache->routes(
            static function () use ($dirs, &$scans): array {
                $scans++;

                return (new Router($dirs))->toEntries();
            },
            static function () use (&$scans): array {
                $scans++;

                return MiddlewareRegistry::discoveredClasses();
            },
        );

        $this->assertSame(2, $scans, 'Corrupt cache must trigger a rescan.');
        $this->assertGreaterThan(0, $entries);

        // The rescan rewrote a valid file — the next build is warm.
        $status = (new DiscoveryCache($dirs, $file))->status();
        $this->assertTrue($status['exists']);
        $this->assertTrue($status['fresh']);
        $this->assertSame(count($entries), $status['routes']);
    }

    #[Test]
    public function versionMismatchFallsBackToScan(): void
    {
        $dirs = [APP_BASE_PATH . '/src/Modules'];
        $file = $this->tmpFile();
        file_put_contents(
            $file,
            "<?php\nreturn " . var_export(['version' => 0, 'fingerprint' => 'x', 'routes' => []], true) . ";\n",
        );

        $cache = new DiscoveryCache($dirs, $file);
        $entries = $cache->routes(
            static fn (): array => (new Router($dirs))->toEntries(),
            static fn (): array => MiddlewareRegistry::discoveredClasses(),
        );

        $this->assertGreaterThan(0, $entries);
        $this->assertTrue((new DiscoveryCache($dirs, $file))->status()['fresh']);
    }

    #[Test]
    public function changedModuleSetInvalidatesTheFingerprint(): void
    {
        $dir = $this->tmpDir();
        $cache = new DiscoveryCache([$dir], $this->tmpFile());

        $stub = [['path' => '/probe', 'method' => 'GET', 'name' => null, 'handlerMethod' => null, 'action' => HomepageAction::class]];
        $scans = 0;
        $scanner = static function () use ($stub, &$scans): array {
            $scans++;

            return $stub;
        };

        $noMiddleware = static fn (): array => [];

        $this->assertSame($stub, $cache->routes($scanner, $noMiddleware));
        $this->assertSame(1, $scans);

        // A new PHP file in the scanned tree changes the fingerprint even
        // when nothing else moves — the next build must rescan.
        file_put_contents($dir . '/Probe.php', "<?php\n// probe\n");

        $this->assertSame($stub, $cache->routes($scanner, $noMiddleware));
        $this->assertSame(2, $scans, 'Changed file set must trigger a rescan.');
    }

    #[Test]
    public function sameSecondSameSizeEditWithPreservedMtimeStillRescans(): void
    {
        $dir = $this->tmpDir();
        $probe = $dir . '/Probe.php';
        file_put_contents($probe, str_repeat('x', 128));
        $mtime = (int) filemtime($probe);

        $cache = new DiscoveryCache([$dir], $this->tmpFile());

        $stub = [['path' => '/probe', 'method' => 'GET', 'name' => null, 'handlerMethod' => null, 'action' => HomepageAction::class]];
        $scans = 0;
        $scanner = static function () use ($stub, &$scans): array {
            $scans++;

            return $stub;
        };

        $noMiddleware = static fn (): array => [];

        $this->assertSame($stub, $cache->routes($scanner, $noMiddleware));
        $this->assertSame(1, $scans);

        // Same size, and the mtime pinned back — a pure size+mtime
        // fingerprint would call this tree identical. The content-hash tier
        // (mtime within one second of the build) must still see the change.
        file_put_contents($probe, str_repeat('y', 128));
        touch($probe, $mtime);

        $this->assertSame($stub, $cache->routes($scanner, $noMiddleware));
        $this->assertSame(2, $scans, 'Same-size edit with preserved mtime must trigger a rescan.');
    }

    #[Test]
    public function middlewareColdMatchesDirectDiscoveryAndPrimeInstantiatesInOrder(): void
    {
        MiddlewareRegistry::reset();

        $dirs = [APP_BASE_PATH . '/src/Modules'];
        $cache = new DiscoveryCache($dirs, $this->tmpFile());

        $scans = 0;
        $scanRoutes = static function () use ($dirs, &$scans): array {
            $scans++;

            return (new Router($dirs))->toEntries();
        };
        $scanMiddleware = static function () use (&$scans): array {
            $scans++;

            return MiddlewareRegistry::discoveredClasses();
        };

        $cold = $cache->middleware($scanRoutes, $scanMiddleware);

        $this->assertSame(2, $scans);
        $this->assertGreaterThan(0, $cold);
        $this->assertSame(MiddlewareRegistry::discoveredClasses(), $cold);

        // Warm: exact same order, no scanner runs.
        $warm = $cache->middleware(
            static function (): array {
                throw new RuntimeException('Warm build must not run the scanner.');
            },
            static function (): array {
                throw new RuntimeException('Warm build must not run the scanner.');
            },
        );

        $this->assertSame($cold, $warm);

        // Priming instantiates the cached classes in order — the same
        // instances `all()` would have discovered itself.
        MiddlewareRegistry::prime($warm);
        $instances = MiddlewareRegistry::all();

        $this->assertSame(count($warm), count($instances));

        foreach ($instances as $i => $instance) {
            $this->assertInstanceOf($warm[$i], $instance);
        }
    }

    #[Test]
    public function installingAModuleInvalidatesTheDiscoveryCache(): void
    {
        $cacheFile = APP_BASE_PATH . '/storage/cache/' . DiscoveryCache::FILE;

        // Warm the real cache first (direct scans as the source).
        $warmed = DiscoveryCache::fromAppPaths()->routes(
            static fn (): array => (new Router([APP_BASE_PATH . '/src/Modules']))->toEntries(),
            static fn (): array => MiddlewareRegistry::discoveredClasses(),
        );
        $this->assertGreaterThan(0, $warmed);
        $this->assertFileExists($cacheFile);

        // A cleanup failure is recorded here rather than thrown from `finally`:
        // a throw there would replace whatever the assertion above raised, and
        // the odd shape is what the lint rule (rightly) flags.
        $cleanupFailure = null;

        try {
            ModuleInstaller::fromAppPaths()->add('Scratch');

            $this->assertFileDoesNotExist(
                $cacheFile,
                'Installing a module must invalidate the discovery cache (same module set, same fate as the migration marker).',
            );
        } finally {
            try {
                ModuleInstaller::fromAppPaths()->remove('Scratch', true);
            } catch (RuntimeException $e) {
                if (!str_contains($e->getMessage(), 'is not installed')) {
                    $cleanupFailure = $e;
                }
            }

            DiscoveryCache::clearCache();
        }

        if (null !== $cleanupFailure) {
            throw $cleanupFailure;
        }
    }

    #[Test]
    public function cmsDiscoveryCommandsClearAndReport(): void
    {
        DiscoveryCache::fromAppPaths()->routes(
            static fn (): array => (new Router([APP_BASE_PATH . '/src/Modules']))->toEntries(),
            static fn (): array => MiddlewareRegistry::discoveredClasses(),
        );

        $status = $this->cms('discovery:status');
        $this->assertStringContainsString('Exists:      yes', $status);
        $this->assertStringContainsString('Fresh:       yes', $status);
        $this->assertStringContainsString('Middleware:', $status);

        $cleared = $this->cms('discovery:clear');
        $this->assertStringContainsString('cleared', $cleared);
        $this->assertFileDoesNotExist(APP_BASE_PATH . '/storage/cache/' . DiscoveryCache::FILE);

        $stale = $this->cms('discovery:status');
        $this->assertStringContainsString('Exists:      no', $stale);
    }

    private function cms(string $command): string
    {
        $lines = [];
        $exit = -1;
        exec('php ' . escapeshellarg(APP_BASE_PATH . '/bin/cms') . ' ' . escapeshellarg($command) . ' 2>&1', $lines, $exit);

        $this->assertSame(0, $exit, 'bin/cms ' . $command . ' failed: ' . implode("\n", $lines));

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<string>
     */
    private function canonicalize(array $entries): array
    {
        $flat = array_map(static fn (array $e): string => (string) json_encode($e), $entries);
        sort($flat);

        return $flat;
    }

    private function tmpFile(): string
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'discovery-cache-test-' . bin2hex(random_bytes(8)) . '.php';
        $this->tmpFiles[] = $file;

        return $file;
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'discovery-scan-test-' . bin2hex(random_bytes(8));

        if (!mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create temp scan dir: ' . $dir);
        }

        $this->tmpDirs[] = $dir;

        return $dir;
    }
}
