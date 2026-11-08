<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * DiscoveryCache — fingerprint-validated cache for the Router scan.
 *
 * The Router rescans every PHP file under the action dirs on each boot
 * (per request in `src/http.php`, per Kernel in tests). This cache stores
 * that scan's output (RFC-1, `.ai-factory/plans/rfc-discovery-cache-preference.md`):
 *
 * - payload: `version` + `builtAt` + `fingerprint` + `routes` (the exact entry
 *   shape `Router::toEntries()` produces, so a warm Router is identical to a
 *   freshly scanned one, including scan order) + `middleware` (the ordered
 *   class list `MiddlewareRegistry::discoveredClasses()` produces, rehydrated
 *   by `MiddlewareRegistry::prime()`);
 * - format: `<?php return [...];` via `var_export`, written atomically
 *   (temp file in the same directory + `rename`);
 * - location: `storage/cache/discovery.php` (gitignored runtime state —
 *   excluded from the `module:add/remove` round-trip hash like the rest of
 *   `storage/cache/`; never `src/` nor `modules/`).
 *
 * Deliberate deviations from the Marko `DiscoveryCache` this ports the idea
 * from (reasons in the RFC):
 *
 * - v1 caches ROUTE ENTRIES only, not module manifests — manifests stay
 *   live-scanned by `bin/cms` tooling, which is not a hot path;
 * - the scan itself stays TEXT-FIRST: entries come from a real `Router`
 *   scan passed in as a callable, so the `#[Route]`-in-prose permissiveness
 *   and the symfony-parent tolerance are unchanged;
 * - fingerprint is TWO-TIER: `*.php` path + size + mtime, except files touched
 *   within one second of the build (`mtime >= builtAt - 1`), which contribute
 *   a content hash instead. Hashing every file on the warm path would cost
 *   the read the cache is meant to skip; hashing only the freshly-touched
 *   ones (usually none) closes the same-second same-size blind spot that a
 *   pure mtime fingerprint has. `php bin/cms discovery:clear` stays the
 *   escape hatch for the pathological remainder (a manually preserved mtime);
 * - no owner trust-checks (shared-hosting reality) — only the world-writable
 *   bit refuses a cache file, with a log line, never a fatal;
 * - every failure mode degrades to NULL (rescan), never an exception outward:
 *   a corrupt cache must cost one scan, not a 500.
 */
final readonly class DiscoveryCache
{
    public const int VERSION = 3;

    public const string FILE = 'discovery.php';

    /**
     * @param list<string> $scanDirs directories whose *.php files the fingerprint covers
     */
    public function __construct(
        private array $scanDirs,
        private string $cacheFile,
    ) {
    }

    public static function fromAppPaths(): self
    {
        $paths = AppPaths::instance();

        return new self([$paths->src('Modules')], $paths->cache(self::FILE));
    }

    /**
     * Delete the cache file (idempotent). Called by `ModuleInstaller::add()`
     * and `remove()` beside `Bootstrapper::invalidateMigrations()`, and by
     * `php bin/cms discovery:clear`.
     */
    public static function clearCache(): void
    {
        $path = AppPaths::instance()->cache(self::FILE);

        if (is_file($path) && !@unlink($path)) {
            error_log('[discovery] cannot remove stale cache file: ' . $path);
        }
    }

    /**
     * Route entries, from cache when fresh, otherwise from the scanners.
     * Both scanners always run together: one fingerprint guards the whole
     * payload, so a partial build can never land on disk (see `build()`).
     *
     * @param callable(): list<array{path: string, method: string, name: string|null, handlerMethod: string|null, action: class-string}> $scanRoutes
     * @param callable(): list<class-string>                                                                                             $scanMiddleware
     *
     * @return list<array{path: string, method: string, name: string|null, handlerMethod: string|null, action: class-string}>
     */
    public function routes(callable $scanRoutes, callable $scanMiddleware): array
    {
        return $this->build($scanRoutes, $scanMiddleware)['routes'];
    }

    /**
     * Middleware classes — the same cold/warm contract as `routes()`.
     *
     * @param callable(): list<array{path: string, method: string, name: string|null, handlerMethod: string|null, action: class-string}> $scanRoutes
     * @param callable(): list<class-string>                                                                                             $scanMiddleware
     *
     * @return list<class-string>
     */
    public function middleware(callable $scanRoutes, callable $scanMiddleware): array
    {
        return $this->build($scanRoutes, $scanMiddleware)['middleware'];
    }

    /**
     * Both sections at once: the only path that WRITES the cache file, so a
     * partially-built payload can never land on disk.
     *
     * @param callable(): list<array{path: string, method: string, name: string|null, handlerMethod: string|null, action: class-string}> $scanRoutes
     * @param callable(): list<class-string>                                                                                             $scanMiddleware
     *
     * @return array{routes: list<array{path: string, method: string, name: string|null, handlerMethod: string|null, action: class-string}>, middleware: list<class-string>}
     */
    public function build(callable $scanRoutes, callable $scanMiddleware): array
    {
        $cached = $this->load();

        if (null !== $cached) {
            return $cached;
        }

        $payload = ['routes' => $scanRoutes(), 'middleware' => $scanMiddleware()];
        $this->write($payload);

        return $payload;
    }

    /**
     * @return array{exists: bool, fresh: bool, routes: int, middleware: int, fingerprint: string, version: int}
     */
    public function status(): array
    {
        $cached = $this->load();

        return [
            'exists'      => is_file($this->cacheFile),
            'fresh'       => null !== $cached,
            'routes'      => null === $cached ? 0 : count($cached['routes']),
            'middleware'  => null === $cached ? 0 : count($cached['middleware']),
            'fingerprint' => $this->fingerprint(time()),
            'version'     => self::VERSION,
        ];
    }

    /**
     * Null on every failure mode (missing file, version or fingerprint
     * mismatch, malformed payload, world-writable file) — the caller rescans.
     *
     * The fingerprint is recomputed against the STORED build time, so the
     * content-hash tier covers the same files it covered when the cache was
     * written (a file touched just before the build must hash now too).
     *
     * @return array{routes: list<array{path: string, method: string, name: string|null, handlerMethod: string|null, action: class-string}>, middleware: list<class-string>}|null
     */
    private function load(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        $perms = @fileperms($this->cacheFile);

        if (false !== $perms && 0 !== ($perms & 0o002)) {
            error_log('[discovery] ignoring world-writable cache file: ' . $this->cacheFile);

            return null;
        }

        $data = @include $this->cacheFile;

        if (!is_array($data)) {
            return null;
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            return null;
        }

        if (!is_int($data['builtAt'] ?? null)) {
            return null;
        }

        if (($data['fingerprint'] ?? null) !== $this->fingerprint($data['builtAt'])) {
            return null;
        }

        if (!is_array($data['routes'] ?? null)) {
            return null;
        }

        if (!is_array($data['middleware'] ?? null)) {
            return null;
        }

        foreach ($data['middleware'] as $class) {
            if (!is_string($class)) {
                return null;
            }
        }

        foreach ($data['routes'] as $entry) {
            if (!is_array($entry)
                || !isset($entry['path'], $entry['method'], $entry['action'])
                || !is_string($entry['path'])
                || !is_string($entry['method'])
                || !is_string($entry['action'])
                || (array_key_exists('name', $entry) && null !== $entry['name'] && !is_string($entry['name']))
                || (array_key_exists('handlerMethod', $entry) && null !== $entry['handlerMethod'] && !is_string($entry['handlerMethod']))
            ) {
                return null;
            }
        }

        /** @var list<array{path: string, method: string, name: string|null, handlerMethod: string|null, action: class-string}> $routes */
        $routes = array_values($data['routes']);

        /** @var list<class-string> $middleware */
        $middleware = array_values($data['middleware']);

        return ['routes' => $routes, 'middleware' => $middleware];
    }

    /**
     * @param array{routes: list<array{path: string, method: string, name: string|null, handlerMethod: string|null, action: class-string}>, middleware: list<class-string>} $payload
     */
    private function write(array $payload): void
    {
        $dir = dirname($this->cacheFile);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            error_log('[discovery] cannot create cache directory: ' . $dir);

            return;
        }

        $builtAt = time();
        $data = [
            'version'     => self::VERSION,
            'builtAt'     => $builtAt,
            'fingerprint' => $this->fingerprint($builtAt),
            'routes'      => $payload['routes'],
            'middleware'  => $payload['middleware'],
        ];

        $tmp = $dir . DIRECTORY_SEPARATOR . '.discovery-' . bin2hex(random_bytes(8)) . '.tmp';
        $content = "<?php\n// Discovery cache — generated. Do not edit manually. Use `php bin/cms discovery:clear`.\nreturn " . var_export($data, true) . ";\n";

        if (false === @file_put_contents($tmp, $content)) {
            error_log('[discovery] cannot write cache temp file: ' . $tmp);

            return;
        }

        if (!@rename($tmp, $this->cacheFile)) {
            @unlink($tmp);
            error_log('[discovery] cannot publish cache file: ' . $this->cacheFile);
        }
    }

    /**
     * Sorted dir list + every *.php file's relative path and either its
     * size+mtime (cold files) or its content hash (files touched within one
     * second of $builtAt — the same-second same-size window a pure mtime
     * fingerprint cannot see).
     *
     * `clearstatcache()` first: within one process (tests, long-lived CLI)
     * PHP caches stat results and a file written seconds ago would otherwise
     * fingerprint identically to its previous content.
     */
    private function fingerprint(int $builtAt): string
    {
        clearstatcache();

        $parts = [];

        $dirs = $this->scanDirs;
        sort($dirs);

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                $parts[] = $dir . ':missing';

                continue;
            }

            $parts[] = $dir . ':dir';

            $files = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && 'php' === $file->getExtension()) {
                    $files[] = $file->getPathname();
                }
            }

            sort($files);

            $prefix = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

            foreach ($files as $file) {
                $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
                $mtime = @filemtime($file);
                $mtime = is_int($mtime) ? $mtime : 0;

                if ($mtime >= $builtAt - 1) {
                    $hash = @md5_file($file);
                    $parts[] = $relative . ':md5:' . (is_string($hash) ? $hash : ((string) @filesize($file)) . ':' . ((string) $mtime));
                } else {
                    $parts[] = $relative . ':' . ((string) @filesize($file)) . ':' . ((string) $mtime);
                }
            }
        }

        return hash('sha256', implode("\n", $parts));
    }
}
