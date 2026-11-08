<?php

declare(strict_types = 1);

namespace Tests\Modules;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Tooling\ModuleInstaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ModuleLifecycleTest (task 3.3) — the round trip the CLI promises:
 *
 *   hash → add Scratch → its route 200s → second add refuses →
 *   remove → its route 404s → hash equals the pre-add value.
 *
 * Plus the negative from the guard's contract: removing a module another
 * installed module imports is refused, with the importing file named.
 *
 * Two process subtleties are load-bearing, not incidental:
 *
 * - The 404 leg runs in a SUBPROCESS. PHP's class table persists per
 *   process: after remove(), the in-process Router would still resolve the
 *   deleted ScratchAction (already declared) and answer 200 — the exact
 *   staleness trap the opcache incident taught us. A fresh `php -r` is the
 *   only honest 404.
 * - The tree hash excludes what the shell hash excludes (.git, volatile
 *   storage, sqlite journals) plus .phpunit.result.cache, which PHPUnit
 *   itself writes mid-run.
 */
final class ModuleLifecycleTest extends TestCase
{
    private const string LEAF = 'Scratch';

    private const string HOLDER = 'TempHolder';

    #[Test]
    public function roundTripLeavesAByteIdenticalTree(): void
    {
        $before = self::hashTree();

        try {
            $installer = ModuleInstaller::fromAppPaths();

            // Add the measured leaf; its route answers through the Kernel.
            $added = $installer->add(self::LEAF);
            $this->assertGreaterThan(0, $added['copied']);

            $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));
            $response = $kernel->handle(Request::create('GET', '/scratch'));
            $this->assertSame(200, $response->getStatusCode());

            // A second add refuses — the installed slice is never overwritten.
            try {
                $installer->add(self::LEAF);
                $this->fail('Second add should refuse.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('already installed', $e->getMessage());
            }

            $installer->remove(self::LEAF);

            // 404 in a FRESH process — see the class-table note above.
            $this->assertSame('404', $this->dispatchInSubprocess('/scratch'));

            // Negative: Scratch is imported by an installed holder → refusal names it.
            self::createHolderFixture();
            $installer = ModuleInstaller::fromAppPaths();
            $installer->add(self::LEAF);
            $installer->add(self::HOLDER);

            try {
                $installer->remove(self::LEAF);
                $this->fail('Removal while imported should refuse.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(self::HOLDER, $e->getMessage());
            }
        } finally {
            self::cleanup();
        }

        $this->assertSame($before, self::hashTree(), 'Round trip must leave a byte-identical tree.');
    }

    /**
     * Installing a slice must drop the first-request migration marker.
     *
     * The marker says "the migrators of this module set have run"; a new
     * module is a new set. Left standing, it suppressed the new module's
     * migration and the first admin write died on a missing table
     * (`audit_log`, Boot 16) — no table, no audit row, 500 on a create.
     */
    #[Test]
    public function installingAModuleInvalidatesTheMigrationMarker(): void
    {
        $marker = APP_BASE_PATH . '/storage/cache/.migrations-applied';

        if (!is_dir(dirname($marker)) && !mkdir(dirname($marker), 0o775, true) && !is_dir(dirname($marker))) {
            throw new RuntimeException('Cannot create storage/cache: ' . dirname($marker));
        }

        file_put_contents($marker, "config\n");

        try {
            $installer = ModuleInstaller::fromAppPaths();
            $installer->add(self::LEAF);

            $this->assertFileDoesNotExist(
                $marker,
                'Installing a module must invalidate the first-request migration marker.',
            );
        } finally {
            self::removeLeaf();

            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    /**
     * Dispatch one path through a fresh interpreter and return the status.
     */
    private function dispatchInSubprocess(string $path): string
    {
        $init = var_export(APP_BASE_PATH . '/src/init.php', true);
        $code = 'require ' . $init . '; '
            . '$router = new Modules\\Core\\Interfaces\\HTTP\\Router([APP_BASE_PATH . \'/src/Modules\']); '
            . '$kernel = new Modules\\Core\\Interfaces\\HTTP\\Kernel($router); '
            . '$response = $kernel->handle(Modules\\Core\\Infrastructure\\Http\\Request::create('
            . '"GET", ' . var_export($path, true) . ')); '
            . 'echo $response->getStatusCode();';

        $lines = [];
        $exit = -1;
        exec('php -r ' . escapeshellarg($code) . ' 2>&1', $lines, $exit);

        $this->assertSame(0, $exit, 'Subprocess dispatch failed: ' . implode("\n", $lines));

        return trim(implode('', $lines));
    }

    /**
     * A throwaway catalog fixture: one file importing the leaf, so the
     * removal guard has a holder to name. Removed in cleanup().
     */
    private static function createHolderFixture(): void
    {
        $dir = APP_BASE_PATH . '/modules/' . self::HOLDER . '/src';

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create holder fixture dir: ' . $dir);
        }

        file_put_contents(
            APP_BASE_PATH . '/modules/' . self::HOLDER . '/module.json',
            json_encode([
                'name'        => self::HOLDER,
                'version'     => '0.0.1',
                'description' => 'Lifecycle-test fixture (removed by the test).',
                'routes'      => [],
                'scripts'     => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        file_put_contents(
            $dir . '/Holder.php',
            "<?php\n\ndeclare(strict_types = 1);\n\nnamespace Modules\\TempHolder\\Application;\n\nuse Modules\\Scratch\\ScratchService;\n\n/** Lifecycle-test fixture: exists only so the removal guard has a holder to name. */\nfinal class Holder\n{\n    public function service(): string\n    {\n        return ScratchService::class;\n    }\n}\n",
        );
    }

    /**
     * Best-effort restore: every step is attempted even if one fails, the
     * first failure rethrown afterwards — a half-cleaned tree must be loud.
     * Explicit per-step blocks (not a dynamic dispatch loop): PHPStan sees
     * the @throws on each step, so the catches are provably live.
     */
    private static function cleanup(): void
    {
        /** @var list<RuntimeException> $failures */
        $failures = [];

        try {
            self::removeHolder();
        } catch (RuntimeException $e) {
            $failures[] = $e;
        }

        try {
            self::removeLeaf();
        } catch (RuntimeException $e) {
            $failures[] = $e;
        }

        try {
            self::deleteHolderFixture();
        } catch (RuntimeException $e) {
            $failures[] = $e;
        }

        if ([] !== $failures) {
            throw $failures[0];
        }
    }

    /**
     * @throws RuntimeException
     */
    private static function removeHolder(): void
    {
        $installer = ModuleInstaller::fromAppPaths();

        try {
            $installer->remove(self::HOLDER);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'is not installed')) {
                throw $e;
            }
        }
    }

    /**
     * @throws RuntimeException
     */
    private static function removeLeaf(): void
    {
        $installer = ModuleInstaller::fromAppPaths();

        try {
            $installer->remove(self::LEAF, true);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'is not installed')) {
                throw $e;
            }
        }
    }

    /**
     * @throws RuntimeException
     */
    private static function deleteHolderFixture(): void
    {
        $dir = APP_BASE_PATH . '/modules/' . self::HOLDER;

        if (is_dir($dir)) {
            ModuleInstaller::deleteTree($dir);
        }
    }

    /**
     * md5 over every tracked byte, sorted — the in-test twin of the shell
     * `find … | sort | md5sum` from the Mono/Cardboard measurements.
     *
     * Streamed (hash_update per file): the first version held every file's
     * content in one array and died at 128M on vendor/ — a test harness
     * that OOMs on a normal tree is a broken harness.
     */
    private static function hashTree(): string
    {
        $base = APP_BASE_PATH;
        $paths = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $rel = substr($file->getPathname(), strlen($base) + 1);
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);

            if (!self::isExcluded($rel)) {
                $paths[] = $rel;
            }
        }

        sort($paths);

        $ctx = hash_init('md5');

        foreach ($paths as $rel) {
            hash_update($ctx, $rel . "\0");
            $content = file_get_contents($base . '/' . $rel);
            hash_update($ctx, is_string($content) ? $content : '');
            hash_update($ctx, "\0");
        }

        return hash_final($ctx);
    }

    private static function isExcluded(string $rel): bool
    {
        if (str_starts_with($rel, '.git/') || '.phpunit.result.cache' === $rel) {
            return true;
        }

        if (str_starts_with($rel, 'storage/logs/')
            || str_starts_with($rel, 'storage/cache/')
            || str_starts_with($rel, 'storage/sessions/')
        ) {
            return true;
        }

        // pnpm's dependency store and every package-local `node_modules/`:
        // gitignored state, and full of symlinks the hash walk would report as
        // leaves and then try to read as files (`file_get_contents(): ... Is a
        // directory`, measured on `Frontend/packages/griffe/node_modules/lit`
        // after griffe gained the `lit` dependency). Not part of the tree.
        if (1 === preg_match('#^Frontend/packages/(?:[^/]+/)?node_modules(/|$)#', $rel)) {
            return true;
        }

        if (str_starts_with($rel, 'storage/data/')) {
            foreach (['.db', '.db-wal', '.db-shm', '.db-journal'] as $suffix) {
                if (str_ends_with($rel, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
