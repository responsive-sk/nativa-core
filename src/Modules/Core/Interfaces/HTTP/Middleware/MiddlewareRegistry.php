<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Infrastructure\Attributes\AsMiddleware;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * MiddlewareRegistry - auto-discovers #[AsMiddleware] classes and provides
 * sorted middleware instances for the Kernel pipeline.
 */
final class MiddlewareRegistry
{
    /** @var list<MiddlewareInterface>|null */
    private static ?array $middlewares = null;

    /**
     * Discover and return all #[AsMiddleware] instances, sorted by priority.
     *
     * @return list<MiddlewareInterface>
     */
    public static function all(): array
    {
        if (null !== self::$middlewares) {
            return self::$middlewares;
        }

        self::$middlewares = self::instantiate(self::discoveredClasses());

        return self::$middlewares;
    }

    /**
     * Discover the #[AsMiddleware] class list, sorted by priority — the same
     * order `all()` instantiates. No instances are created: this is the shape
     * `DiscoveryCache` stores, and `prime()` rehydrates it without rescanning.
     *
     * @return list<class-string<MiddlewareInterface>>
     */
    public static function discoveredClasses(): array
    {
        $found = [];
        $base = AppPaths::instance()->src('Modules');

        $dirs = glob($base . '/*/Interfaces/HTTP/Middleware');
        if (!is_array($dirs)) {
            return [];
        }

        foreach ($dirs as $dir) {
            $files = glob($dir . '/*.php');
            if (!is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                self::scanFile($file, $found);
            }
        }

        uasort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_values(array_map(
            static fn (array $entry): string => $entry[1],
            $found,
        ));
    }

    /**
     * Prime the registry from a cached class list (see `discoveredClasses()`)
     * without rescanning. Each class is instantiated fresh, in order; a class
     * that no longer instantiates is skipped with a log line, never a fatal —
     * a stale list means the fingerprint failed, and the request must still
     * be served.
     *
     * @param list<class-string> $classes
     */
    public static function prime(array $classes): void
    {
        self::$middlewares = self::instantiate($classes);
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$middlewares = null;
    }

    /**
     * @param array<string, array{int, class-string<MiddlewareInterface>}> $found
     */
    private static function scanFile(string $file, array &$found): void
    {
        $content = file_get_contents($file);
        if (!is_string($content) || !str_contains($content, 'AsMiddleware')) {
            return;
        }

        $fqcn = self::resolveFqcn($content);
        if (null === $fqcn || !self::loadAndRegister($fqcn, $file, $found)) {
            return;
        }
    }

    /**
     * Extract the FQCN from a file body: its namespace declaration and its type
     * declaration, and nothing else.
     *
     * A declaration starts a LINE (after optional whitespace and the modifiers
     * PHP allows on a type) or a STATEMENT (after a `;`), so a compact
     * `namespace Demo; class Thing {}` file still resolves — the failure mode
     * here is silence, not an error, and a heuristic that is too strict trades
     * one silent skip for another.
     *
     * What cannot be a declaration is PROSE. This used to take the FIRST mention
     * of the keyword anywhere in the file, so a docblock sentence like "This
     * class says the one thing..." resolved a type named `says`: the class did
     * not exist, the file was skipped with no log line, and the middleware silently
     * disappeared — measured on the Settings shell provider, whose nav menu
     * vanished exactly that way (docs/kernel-status/03-content-themes-and-the-admin-merge.md, Boot 23).
     *
     * tests/Discovery/TextFirstFqcnTest.php pins both halves, for all four
     * registries that share this shape.
     */
    private static function resolveFqcn(string $content): ?string
    {
        $namespace = '';
        $className = '';

        if (preg_match('/(?:^|;)\s*namespace\s+([\w\\\\]+)\s*;/m', $content, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];
        }

        if (preg_match('/(?:^|;)\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $content, $classNameMatch)) {
            $className = $classNameMatch[1];
        }

        if ('' === $namespace || '' === $className) {
            return null;
        }

        return $namespace . '\\' . $className;
    }

    /**
     * Load the class, validate the MiddlewareInterface + #[AsMiddleware]
     * contract (zero-arg constructor) and register its class by priority.
     * Instances are created later (`all()`, `prime()`), so discovery itself
     * stays free of side effects.
     *
     * @param array<string, array{int, class-string<MiddlewareInterface>}> $found
     */
    private static function loadAndRegister(string $fqcn, string $file, array &$found): bool
    {
        if (!class_exists($fqcn)) {
            require_once $file;
        }

        if (!class_exists($fqcn)) {
            return false;
        }

        $reflection = new \ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(MiddlewareInterface::class)) {
            return false;
        }

        $attributes = $reflection->getAttributes(AsMiddleware::class);
        if ([] === $attributes) {
            return false;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        /** @var AsMiddleware $attr */
        $attr = $attributes[0]->newInstance();

        /** @var class-string<MiddlewareInterface> $fqcn */
        $found[$attr->priority . '_' . $fqcn] = [$attr->priority, $fqcn];

        return true;
    }

    /**
     * Instantiate middleware classes in order, skipping what no longer
     * constructs (see `prime()`).
     *
     * @param list<class-string> $classes
     *
     * @return list<MiddlewareInterface>
     */
    private static function instantiate(array $classes): array
    {
        $instances = [];

        foreach ($classes as $class) {
            try {
                $instance = new $class();
            } catch (\Throwable $e) {
                error_log('[middleware] skipping ' . $class . ': ' . $e->getMessage());

                continue;
            }

            if (!$instance instanceof MiddlewareInterface) {
                continue;
            }

            $instances[] = $instance;
        }

        return $instances;
    }
}
